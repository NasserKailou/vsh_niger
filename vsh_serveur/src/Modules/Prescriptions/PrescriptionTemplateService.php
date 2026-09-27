<?php

declare(strict_types=1);

namespace Vsh\Modules\Prescriptions;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;

/**
 * Modèles d'ordonnance (rapport C8) : des DONNÉES saisies et approuvées par la clinique.
 *
 *   BROUILLON ──approve──▶ ACTIF ──archive──▶ ARCHIVE
 *       ▲                    │
 *       └──── modification ──┘  (repasse en brouillon, version clinique + 1, nouvelle approbation)
 *
 * Seuls les modèles ACTIFS sont proposés aux prescripteurs. La suggestion applique uniquement les
 * critères configurés sur le modèle (âge, poids) : aucune règle clinique n'est codée ici.
 */
final class PrescriptionTemplateService
{
    /** @var Database */
    private $db;

    /** @var PrescriptionItems */
    private $items;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        PrescriptionItems $items,
        PatientRepository $patients,
        PatientPolicy $policy,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->items = $items;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($query, [
            'status' => 'nullable|in:BROUILLON,ACTIF,ARCHIVE',
            'q' => 'nullable|string|max:100',
            'population' => 'nullable|in:ADULTE,ENFANT,NOURRISSON,AUTRE',
        ]);
        $where = ['deleted_at IS NULL'];
        $params = [];
        if (!self::canSeeDrafts($auth)) {
            $where[] = "status = 'ACTIF'";
        } elseif (isset($data['status'])) {
            $where[] = 'status = ?';
            $params[] = $data['status'];
        }
        if (isset($data['population'])) {
            $where[] = 'population = ?';
            $params[] = $data['population'];
        }
        if (isset($data['q'])) {
            $where[] = '(name LIKE ? OR pathology LIKE ?)';
            $like = '%' . addcslashes($data['q'], '%_\\') . '%';
            array_push($params, $like, $like);
        }
        $sql = ' FROM prescription_templates WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT *' . $sql . ' ORDER BY pathology, name LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row): array {
            return $this->present($row);
        }, $rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $template = $this->findOrFail($uuid);
        if ($template['status'] !== 'ACTIF' && !self::canSeeDrafts($auth)) {
            throw HttpException::notFound('Modèle introuvable.');
        }
        return $this->present($template);
    }

    public function create(array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, self::rules(true));
        $rows = $this->items->validate((array) ($data['items'] ?? []));
        self::assertRanges($data);

        return $this->db->transaction(function () use ($data, $rows, $auth, $request): array {
            $uuid = Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('prescription_templates', self::columns($data) + [
                'uuid' => $uuid,
                'status' => 'BROUILLON',
                'template_version' => 1,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceItems($id, $rows);
            $this->audit->record('PRESCRIPTION_TEMPLATE_CREATED', $request, 'prescription_template', $uuid, null, ['name' => $data['name']]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescription_templates WHERE id = ?', [$id]));
        });
    }

    /**
     * Toute modification d'un modèle actif le repasse en brouillon : il doit être approuvé à nouveau.
     */
    public function update(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, self::rules(false) + ['version' => 'nullable|integer|min:1']);
        $rows = array_key_exists('items', $data) ? $this->items->validate((array) $data['items']) : null;

        return $this->db->transaction(function () use ($uuid, $data, $rows, $auth, $request): array {
            $template = $this->lock($uuid);
            if ($template['status'] === 'ARCHIVE') {
                throw HttpException::conflict('Un modèle archivé n\'est plus modifiable.', 'INVALID_TRANSITION');
            }
            if (isset($data['version']) && (int) $data['version'] !== (int) $template['version']) {
                throw HttpException::conflict('Le modèle a été modifié entre-temps.', 'VERSION_CONFLICT');
            }
            self::assertRanges(array_merge($template, $data));
            $changes = self::columns($data) + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()];
            if ($template['status'] === 'ACTIF') {
                $changes += [
                    'status' => 'BROUILLON',
                    'template_version' => (int) $template['template_version'] + 1,
                    'approved_by' => null,
                    'approved_at' => null,
                ];
            }
            $this->db->update('prescription_templates', $changes, 'id = ?', [(int) $template['id']]);
            $this->db->execute('UPDATE prescription_templates SET version = version + 1 WHERE id = ?', [(int) $template['id']]);
            $fields = array_keys(self::columns($data));
            if ($rows !== null) {
                $this->replaceItems((int) $template['id'], $rows);
                $fields[] = 'items';
            }
            $this->audit->record('PRESCRIPTION_TEMPLATE_UPDATED', $request, 'prescription_template', $uuid, ['status' => $template['status']], ['fields' => $fields]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescription_templates WHERE id = ?', [(int) $template['id']]));
        });
    }

    public function approve(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        return $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $template = $this->lock($uuid);
            if ($template['status'] !== 'BROUILLON') {
                throw HttpException::conflict('Seul un brouillon peut être approuvé.', 'INVALID_TRANSITION');
            }
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM prescription_template_items WHERE template_id = ?', [(int) $template['id']]) === 0) {
                throw new ValidationException(['items' => ['Un modèle sans médicament ne peut pas être approuvé.']]);
            }
            $now = Clock::nowForDatabase();
            $this->db->update('prescription_templates', [
                'status' => 'ACTIF',
                'approved_by' => $auth->userId(),
                'approved_at' => $now,
                'updated_by' => $auth->userId(),
                'updated_at' => $now,
            ], 'id = ?', [(int) $template['id']]);
            $this->db->execute('UPDATE prescription_templates SET version = version + 1 WHERE id = ?', [(int) $template['id']]);
            $this->audit->record('PRESCRIPTION_TEMPLATE_APPROVED', $request, 'prescription_template', $uuid, null, ['template_version' => (int) $template['template_version']]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescription_templates WHERE id = ?', [(int) $template['id']]));
        });
    }

    public function archive(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        if (!self::canSeeDrafts($auth)) {
            throw HttpException::forbidden();
        }
        return $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $template = $this->lock($uuid);
            if ($template['status'] === 'ARCHIVE') {
                throw HttpException::conflict('Ce modèle est déjà archivé.', 'INVALID_TRANSITION');
            }
            $this->db->update('prescription_templates', ['status' => 'ARCHIVE', 'updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $template['id']]);
            $this->db->execute('UPDATE prescription_templates SET version = version + 1 WHERE id = ?', [(int) $template['id']]);
            $this->audit->record('PRESCRIPTION_TEMPLATE_ARCHIVED', $request, 'prescription_template', $uuid, ['status' => $template['status']], ['status' => 'ARCHIVE']);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescription_templates WHERE id = ?', [(int) $template['id']]));
        });
    }

    /**
     * Modèles actifs compatibles avec les critères configurés, pour un patient donné.
     * Un critère non vérifiable (âge ou poids inconnu) n'exclut pas le modèle : il est signalé.
     */
    public function suggest(array $query, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($query, [
            'patient_id' => 'required|uuid',
            'pathology' => 'nullable|string|max:190',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw HttpException::notFound('Patient introuvable.');
        }
        $this->policy->assertReadMedical($auth, $patient);

        $ageMonths = null;
        if ($patient['birth_date'] !== null) {
            $diff = (new \DateTimeImmutable((string) $patient['birth_date'], new \DateTimeZone('UTC')))->diff(Clock::now());
            $ageMonths = $diff->y * 12 + $diff->m;
        }
        $weight = $this->db->fetchValue(
            'SELECT weight_kg FROM vital_signs WHERE patient_id = ? AND weight_kg IS NOT NULL AND deleted_at IS NULL ORDER BY recorded_at DESC, id DESC LIMIT 1',
            [(int) $patient['id']]
        );
        $weight = $weight !== null ? (float) $weight : null;

        $params = [];
        $sql = "SELECT * FROM prescription_templates WHERE status = 'ACTIF' AND deleted_at IS NULL";
        if (isset($data['pathology'])) {
            $sql .= ' AND pathology LIKE ?';
            $params[] = '%' . addcslashes($data['pathology'], '%_\\') . '%';
        }
        $suggestions = [];
        foreach ($this->db->fetchAll($sql . ' ORDER BY pathology, name', $params) as $row) {
            $toCheck = [];
            if ($row['age_min_months'] !== null || $row['age_max_months'] !== null) {
                if ($ageMonths === null) {
                    $toCheck[] = 'age';
                } elseif (($row['age_min_months'] !== null && $ageMonths < (int) $row['age_min_months'])
                    || ($row['age_max_months'] !== null && $ageMonths > (int) $row['age_max_months'])) {
                    continue;
                }
            }
            if ($row['weight_min_kg'] !== null || $row['weight_max_kg'] !== null) {
                if ($weight === null) {
                    $toCheck[] = 'weight';
                } elseif (($row['weight_min_kg'] !== null && $weight < (float) $row['weight_min_kg'])
                    || ($row['weight_max_kg'] !== null && $weight > (float) $row['weight_max_kg'])) {
                    continue;
                }
            }
            $suggestions[] = $this->present($row) + ['criteria_to_check' => $toCheck];
        }
        return [
            'patient' => ['age_months' => $ageMonths, 'last_weight_kg' => $weight],
            'templates' => $suggestions,
        ];
    }

    /**
     * Modèles du bundle hors ligne. Complet : les modèles actifs. Différentiel : tout modèle modifié
     * depuis `since` qui a été actif ; l'application ne garde que ceux dont le statut est ACTIF.
     */
    public function changedSince(?string $since): array
    {
        if ($since === null) {
            $rows = $this->db->fetchAll("SELECT * FROM prescription_templates WHERE status = 'ACTIF' AND deleted_at IS NULL ORDER BY pathology, name");
        } else {
            $rows = $this->db->fetchAll(
                "SELECT * FROM prescription_templates WHERE updated_at >= ? AND (status <> 'BROUILLON' OR template_version > 1) ORDER BY id",
                [$since]
            );
        }
        return array_map(function (array $row): array {
            return $this->present($row);
        }, $rows);
    }

    public function findOrFail(string $uuid): array
    {
        $template = $this->db->fetchOne('SELECT * FROM prescription_templates WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($template === null) {
            throw HttpException::notFound('Modèle introuvable.');
        }
        return $template;
    }

    public function present(array $row): array
    {
        $items = $this->db->fetchAll('SELECT * FROM prescription_template_items WHERE template_id = ? ORDER BY sort_order, id', [(int) $row['id']]);
        $approver = $row['approved_by'] !== null
            ? $this->db->fetchOne('SELECT uuid, first_name, last_name FROM users WHERE id = ?', [(int) $row['approved_by']])
            : null;
        return [
            'id' => (string) $row['uuid'],
            'name' => (string) $row['name'],
            'pathology' => (string) $row['pathology'],
            'population' => (string) $row['population'],
            'age_min_months' => $row['age_min_months'] !== null ? (int) $row['age_min_months'] : null,
            'age_max_months' => $row['age_max_months'] !== null ? (int) $row['age_max_months'] : null,
            'weight_min_kg' => $row['weight_min_kg'] !== null ? (float) $row['weight_min_kg'] : null,
            'weight_max_kg' => $row['weight_max_kg'] !== null ? (float) $row['weight_max_kg'] : null,
            'contraindications_note' => $row['contraindications_note'],
            'usage_notes' => $row['usage_notes'],
            'template_version' => (int) $row['template_version'],
            'status' => (string) $row['status'],
            'approved_by' => $approver !== null ? ['id' => (string) $approver['uuid'], 'name' => $approver['first_name'] . ' ' . $approver['last_name']] : null,
            'approved_at' => Clock::toIso($row['approved_at']),
            'items' => $this->items->present($items),
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
    }

    private function replaceItems(int $templateId, array $rows): void
    {
        // Les lignes d'un modèle ne sont référencées par rien : l'ordonnance en garde une copie figée.
        $this->db->execute('DELETE FROM prescription_template_items WHERE template_id = ?', [$templateId]);
        $now = Clock::nowForDatabase();
        foreach ($rows as $row) {
            $this->db->insert('prescription_template_items', $row + [
                'uuid' => Uuid::v4(),
                'template_id' => $templateId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function lock(string $uuid): array
    {
        $template = $this->db->fetchOne('SELECT * FROM prescription_templates WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($template === null) {
            throw HttpException::notFound('Modèle introuvable.');
        }
        return $template;
    }

    private static function rules(bool $creating): array
    {
        $required = $creating ? 'required|' : '';
        return [
            'name' => $required . 'string|max:190',
            'pathology' => $required . 'string|max:190',
            'population' => $required . 'in:ADULTE,ENFANT,NOURRISSON,AUTRE',
            'age_min_months' => 'nullable|integer|min:0|max:1500',
            'age_max_months' => 'nullable|integer|min:0|max:1500',
            'weight_min_kg' => 'nullable|numeric|min:0|max:500',
            'weight_max_kg' => 'nullable|numeric|min:0|max:500',
            'contraindications_note' => 'nullable|string|max:2000',
            'usage_notes' => 'nullable|string|max:2000',
            'items' => 'nullable|array',
        ];
    }

    private static function columns(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'name', 'pathology', 'population', 'age_min_months', 'age_max_months',
            'weight_min_kg', 'weight_max_kg', 'contraindications_note', 'usage_notes',
        ]));
    }

    private static function assertRanges(array $data): void
    {
        $errors = [];
        if (isset($data['age_min_months'], $data['age_max_months']) && (int) $data['age_max_months'] < (int) $data['age_min_months']) {
            $errors['age_max_months'] = ['L\'âge maximal doit être supérieur ou égal à l\'âge minimal.'];
        }
        if (isset($data['weight_min_kg'], $data['weight_max_kg']) && (float) $data['weight_max_kg'] < (float) $data['weight_min_kg']) {
            $errors['weight_max_kg'] = ['Le poids maximal doit être supérieur ou égal au poids minimal.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private static function canSeeDrafts(AuthContext $auth): bool
    {
        return $auth->can('prescription_templates.manage') || $auth->can('prescription_templates.approve');
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
