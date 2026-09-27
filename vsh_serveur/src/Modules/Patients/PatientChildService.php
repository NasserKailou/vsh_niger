<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Geo;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;

/**
 * Contacts, adresses, allergies, antécédents et traitements en cours d'un patient.
 * Les suppressions sont logiques (deleted_at) et propagées aux appareils par le journal de synchronisation.
 */
final class PatientChildService
{
    /** @var Database */
    private $db;

    /** @var PatientPolicy */
    private $policy;

    /** @var Validator */
    private $validator;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    public function __construct(
        Database $db,
        PatientPolicy $policy,
        Validator $validator,
        ChangeJournal $journal,
        AuditLogger $audit
    ) {
        $this->db = $db;
        $this->policy = $policy;
        $this->validator = $validator;
        $this->journal = $journal;
        $this->audit = $audit;
    }

    /**
     * Lecture sans contrôle d'accès (l'appelant l'a déjà effectué).
     */
    /**
     * Élément non supprimé, identifié par son uuid (quel que soit le patient), ou null.
     */
    public function findRow(string $name, string $uuid): ?array
    {
        $definition = PatientChildren::get($name);
        return $this->db->fetchOne('SELECT * FROM `' . $definition['table'] . '` WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
    }

    public function uuidExists(string $name, string $uuid): bool
    {
        $definition = PatientChildren::get($name);
        return $this->db->fetchValue('SELECT id FROM `' . $definition['table'] . '` WHERE uuid = ?', [$uuid]) !== null;
    }

    public function presentRow(string $name, array $row): array
    {
        return $this->presentMany(PatientChildren::get($name), [$row])[0];
    }

    public function rows(string $name, int $patientId): array
    {
        $definition = PatientChildren::get($name);
        $rows = $this->db->fetchAll(
            'SELECT * FROM `' . $definition['table'] . '` WHERE patient_id = ? AND deleted_at IS NULL ORDER BY ' . $definition['order'],
            [$patientId]
        );
        return $this->presentMany($definition, $rows);
    }

    public function list(string $name, array $patient, Request $request): array
    {
        $definition = PatientChildren::get($name);
        $auth = self::auth($request);
        if ($definition['medical']) {
            $this->policy->assertReadMedical($auth, $patient);
            $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'patient', (string) $patient['uuid'], null, ['section' => $name]);
        }
        return $this->rows($name, (int) $patient['id']);
    }

    /**
     * @param string|null $uuid Identifiant généré par l'appareil (création hors ligne)
     */
    public function create(string $name, array $patient, array $input, Request $request, ?string $uuid = null): array
    {
        $definition = PatientChildren::get($name);
        $this->assertCanWrite($definition, $patient, $request);
        $data = $this->validate($name, $input, true);

        return $this->db->transaction(function () use ($name, $definition, $patient, $data, $request, $uuid): array {
            $item = $this->insert($name, (int) $patient['id'], $data, self::auth($request)->userId(), $uuid);
            $this->audit->record('PATIENT_DATA_CREATED', $request, $definition['entity'], $item['id'], null, $item + ['patient_id' => $patient['uuid']]);
            return $item;
        });
    }

    public function update(string $name, array $patient, string $uuid, array $input, Request $request): array
    {
        $definition = PatientChildren::get($name);
        $this->assertCanWrite($definition, $patient, $request);
        $row = $this->findOrFail($definition, (int) $patient['id'], $uuid);
        $before = $this->presentMany($definition, [$row])[0];
        $data = $this->validate($name, $input, false, $before);

        return $this->db->transaction(function () use ($definition, $patient, $row, $uuid, $data, $before, $request): array {
            $this->db->update($definition['table'], $data + ['updated_at' => Clock::nowForDatabase(), 'updated_by' => self::auth($request)->userId()], 'id = ?', [(int) $row['id']]);
            $this->db->execute('UPDATE `' . $definition['table'] . '` SET version = version + 1 WHERE id = ?', [(int) $row['id']]);
            $this->applyExclusive($definition, (int) $patient['id'], (int) $row['id'], $data);
            $this->journal->record($definition['entity'], $uuid, ChangeJournal::UPSERT, (int) $patient['id']);

            $after = $this->presentMany($definition, [(array) $this->db->fetchOne('SELECT * FROM `' . $definition['table'] . '` WHERE id = ?', [(int) $row['id']])])[0];
            list($old, $new) = AuditLogger::changes($before, $after);
            $this->audit->record('PATIENT_DATA_UPDATED', $request, $definition['entity'], $uuid, $old, $new);
            return $after;
        });
    }

    public function delete(string $name, array $patient, string $uuid, Request $request): void
    {
        $definition = PatientChildren::get($name);
        $this->assertCanWrite($definition, $patient, $request);
        $row = $this->findOrFail($definition, (int) $patient['id'], $uuid);
        $before = $this->presentMany($definition, [$row])[0];

        $this->db->transaction(function () use ($definition, $patient, $row, $uuid, $before, $request): void {
            $now = Clock::nowForDatabase();
            $this->db->update($definition['table'], ['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => self::auth($request)->userId()], 'id = ?', [(int) $row['id']]);
            $this->db->execute('UPDATE `' . $definition['table'] . '` SET version = version + 1 WHERE id = ?', [(int) $row['id']]);
            $this->journal->record($definition['entity'], $uuid, ChangeJournal::DELETE, (int) $patient['id']);
            $this->audit->record('PATIENT_DATA_DELETED', $request, $definition['entity'], $uuid, $before, null);
        });
    }

    /**
     * Valide une liste d'éléments transmise avec le dossier (création, inscription).
     * Les erreurs sont rapportées sous la forme « contacts.0.phone ».
     */
    public function validateList(string $name, array $items, string $errorPrefix): array
    {
        $validated = [];
        $errors = [];
        foreach (array_values($items) as $index => $item) {
            try {
                $validated[] = $this->validate($name, is_array($item) ? $item : [], true);
            } catch (ValidationException $exception) {
                foreach ($exception->getErrors() as $field => $messages) {
                    $errors[$errorPrefix . '.' . $index . '.' . $field] = $messages;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $validated;
    }

    /**
     * Insertion sans contrôle d'accès, pour des données déjà validées (à appeler dans une transaction).
     */
    public function insert(string $name, int $patientId, array $data, ?int $actorId, ?string $uuid = null): array
    {
        $definition = PatientChildren::get($name);
        $uuid = $uuid ?? Uuid::v4();
        $now = Clock::nowForDatabase();
        $row = $data + [
            'uuid' => $uuid,
            'patient_id' => $patientId,
            'created_by' => $actorId,
            'updated_by' => $actorId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (!empty($definition['recorded_by'])) {
            $row['recorded_by'] = $actorId;
        }
        $id = $this->db->insert($definition['table'], $row);
        $this->applyExclusive($definition, $patientId, $id, $data);
        $this->journal->record($definition['entity'], $uuid, ChangeJournal::UPSERT, $patientId);
        return $this->presentMany($definition, [(array) $this->db->fetchOne('SELECT * FROM `' . $definition['table'] . '` WHERE id = ?', [$id])])[0];
    }

    private function validate(string $name, array $input, bool $creation, array $current = []): array
    {
        $definition = PatientChildren::get($name);
        $rules = $definition['fields'];
        if (!$creation) {
            $rules = array_map(function (string $rule): string {
                return trim(str_replace(['required|', '|required', 'required'], '', $rule), '|');
            }, $rules);
        }
        $data = $this->validator->validate($input, $rules);

        foreach ($definition['references'] ?? [] as $field => $table) {
            if (isset($data[$field])) {
                $id = $this->db->fetchValue('SELECT id FROM `' . $table . '` WHERE uuid = ?', [$data[$field]]);
                if ($id === null) {
                    throw new ValidationException([$field => ['Élément introuvable dans le référentiel.']]);
                }
                $data[$field] = (int) $id;
            }
        }

        $merged = array_merge($current, $data);
        $errors = [];
        switch ($definition['check'] ?? null) {
            case 'coordinates':
                if (($merged['latitude'] ?? null) === null xor ($merged['longitude'] ?? null) === null) {
                    $errors['longitude'] = ['La latitude et la longitude doivent être renseignées ensemble.'];
                }
                Geo::assertUsable($merged);
                break;
            case 'period':
                if (isset($merged['started_on'], $merged['ended_on']) && $merged['ended_on'] < $merged['started_on']) {
                    $errors['ended_on'] = ['La date de fin doit être postérieure à la date de début.'];
                }
                break;
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $data;
    }

    private function applyExclusive(array $definition, int $patientId, int $id, array $data): void
    {
        $field = $definition['exclusive'] ?? null;
        if ($field !== null && !empty($data[$field])) {
            $this->db->execute(
                'UPDATE `' . $definition['table'] . '` SET `' . $field . '` = 0, version = version + 1 WHERE patient_id = ? AND id <> ? AND `' . $field . '` = 1',
                [$patientId, $id]
            );
        }
    }

    private function assertCanWrite(array $definition, array $patient, Request $request): void
    {
        $auth = self::auth($request);
        if ($definition['medical']) {
            $this->policy->assertWriteMedical($auth, $patient);
        } elseif (!$auth->can('patients.update')) {
            throw HttpException::forbidden();
        }
        if (in_array($patient['status'], ['MERGED', 'REJECTED'], true)) {
            throw HttpException::conflict('Ce dossier est clos et ne peut plus être modifié.', 'PATIENT_CLOSED');
        }
    }

    private function findOrFail(array $definition, int $patientId, string $uuid): array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM `' . $definition['table'] . '` WHERE uuid = ? AND patient_id = ? AND deleted_at IS NULL',
            [$uuid, $patientId]
        );
        if ($row === null) {
            throw HttpException::notFound($definition['not_found']);
        }
        return $row;
    }

    private function presentMany(array $definition, array $rows): array
    {
        $referenceUuids = [];
        foreach ($definition['references'] ?? [] as $field => $table) {
            $ids = array_values(array_unique(array_filter(array_column($rows, $field))));
            $referenceUuids[$field] = [];
            if ($ids !== []) {
                foreach ($this->db->fetchAll(
                    'SELECT id, uuid FROM `' . $table . '` WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                    $ids
                ) as $reference) {
                    $referenceUuids[$field][(int) $reference['id']] = (string) $reference['uuid'];
                }
            }
        }

        return array_map(function (array $row) use ($definition, $referenceUuids): array {
            $item = ['id' => (string) $row['uuid']];
            foreach ($definition['fields'] as $field => $rules) {
                $value = $row[$field] ?? null;
                if ($value === null) {
                    $item[$field] = null;
                } elseif (isset($referenceUuids[$field])) {
                    $item[$field] = $referenceUuids[$field][(int) $value] ?? null;
                } elseif (in_array($field, $definition['datetime'] ?? [], true)) {
                    $item[$field] = Clock::toIso((string) $value);
                } elseif (strpos($rules, 'boolean') !== false) {
                    $item[$field] = (bool) $value;
                } elseif (strpos($rules, 'numeric') !== false || strpos($rules, 'latitude') !== false || strpos($rules, 'longitude') !== false) {
                    $item[$field] = (float) $value;
                } else {
                    $item[$field] = (string) $value;
                }
            }
            $item['version'] = (int) $row['version'];
            $item['created_at'] = Clock::toIso((string) $row['created_at']);
            $item['updated_at'] = Clock::toIso((string) $row['updated_at']);
            return $item;
        }, $rows);
    }

    private static function auth(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        return $auth;
    }
}
