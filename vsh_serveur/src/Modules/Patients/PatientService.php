<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\SequenceGenerator;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Settings\SettingsService;
use Vsh\Modules\Sync\PatientSnapshot;

/**
 * Dossier patient côté personnel : création, recherche, identité, médecin traitant, profil médical, fusion.
 */
final class PatientService
{
    public const IDENTITY_RULES = [
        'first_name' => 'required|string|max:100',
        'last_name' => 'required|string|max:100',
        'sex' => 'required|in:M,F',
        'birth_date' => 'nullable|date',
        'birth_date_is_estimated' => 'boolean',
        'phone' => 'nullable|phone',
    ];

    /** @var Database */
    private $db;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientChildService */
    private $children;

    /** @var PatientPolicy */
    private $policy;

    /** @var SequenceGenerator */
    private $sequences;

    /** @var SettingsService */
    private $settings;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    /** @var PatientSnapshot */
    private $snapshot;

    public function __construct(
        Database $db,
        PatientRepository $patients,
        PatientChildService $children,
        PatientPolicy $policy,
        SequenceGenerator $sequences,
        SettingsService $settings,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator,
        PatientSnapshot $snapshot
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->children = $children;
        $this->policy = $policy;
        $this->sequences = $sequences;
        $this->settings = $settings;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->snapshot = $snapshot;
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination): array
    {
        $filters = $this->validator->validate($query, ['status' => 'nullable|in:PENDING,ACTIVE,INACTIVE,REJECTED']);
        list($rows, $total) = $this->patients->search(array_filter($filters), $pagination->perPage(), $pagination->offset());
        return [$this->presentMany($rows), $total];
    }

    /**
     * Recherche par POST : aucune donnée personnelle dans l'URL (journaux des serveurs et proxys).
     *
     * @return array{0: array[], 1: int}
     */
    public function search(array $input, Pagination $pagination): array
    {
        $criteria = array_filter($this->validator->validate($input, [
            'q' => 'nullable|string|min:2|max:100',
            'file_number' => 'nullable|string|max:30',
            'phone' => 'nullable|phone',
            'birth_date' => 'nullable|date',
            'status' => 'nullable|in:PENDING,ACTIVE,INACTIVE,REJECTED',
        ]), function ($value): bool {
            return $value !== null;
        });
        if (array_diff_key($criteria, ['status' => true]) === []) {
            throw new ValidationException(['q' => ['Indiquez au moins un critère : nom, n° de dossier, téléphone ou date de naissance.']]);
        }
        if (isset($criteria['file_number'])) {
            $criteria['file_number'] = strtoupper($criteria['file_number']);
        }
        list($rows, $total) = $this->patients->search($criteria, $pagination->perPage(), $pagination->offset());
        return [$this->presentMany($rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        $patient = $this->findOrFail($uuid);
        return $this->presentDetailed($patient, $request);
    }

    /**
     * Création par le personnel (accueil, équipe à domicile) : le dossier est validé d'emblée et numéroté.
     */
    public function create(array $input, Request $request): array
    {
        return $this->createRecord($input, $request, false);
    }

    /**
     * Création transmise par un appareil (hors ligne) : l'utilisateur ne peut pas confirmer un doublon
     * au moment de l'envoi. Le dossier est donc créé et marqué « doublon possible » pour l'accueil (rapport §E5).
     */
    public function createFromSync(array $input, Request $request): array
    {
        return $this->createRecord($input, $request, true);
    }

    private function createRecord(array $input, Request $request, bool $flagDuplicates): array
    {
        $data = $this->validator->validate($input, self::IDENTITY_RULES + [
            'id' => 'nullable|uuid',
            'source' => 'nullable|in:CLINIC,HOMECARE',
            'attending_physician_id' => 'nullable|uuid',
            'confirm_not_duplicate' => 'nullable|boolean',
            'contacts' => 'nullable|array|max:10',
            'addresses' => 'nullable|array|max:10',
        ]);
        $this->assertBirthDate($data);
        if (isset($data['id']) && $this->patients->uuidExists($data['id'])) {
            throw HttpException::conflict('Ce dossier existe déjà.', 'ALREADY_EXISTS');
        }
        $attendingId = isset($data['attending_physician_id']) ? $this->resolvePhysician($data['attending_physician_id']) : null;
        $contacts = $this->children->validateList('contacts', $data['contacts'] ?? [], 'contacts');
        $addresses = $this->children->validateList('addresses', $data['addresses'] ?? [], 'addresses');

        $candidates = $this->patients->duplicateCandidates($data['first_name'], $data['last_name'], $data['birth_date'] ?? null, $data['phone'] ?? null, null);
        $possibleDuplicateOf = null;
        if ($candidates !== [] && $flagDuplicates) {
            $possibleDuplicateOf = (int) $candidates[0]['id'];
        } elseif ($candidates !== [] && empty($data['confirm_not_duplicate'])) {
            throw new HttpException(
                409,
                'POSSIBLE_DUPLICATE',
                'Un dossier semblable existe déjà. Vérifiez-le, ou confirmez qu\'il s\'agit d\'une autre personne (confirm_not_duplicate).',
                ['duplicates' => $this->presentMany($candidates)]
            );
        }
        $actorId = self::auth($request)->userId();

        return $this->db->transaction(function () use ($data, $attendingId, $contacts, $addresses, $actorId, $possibleDuplicateOf, $request): array {
            $now = Clock::nowForDatabase();
            $id = $this->patients->create([
                'possible_duplicate_of' => $possibleDuplicateOf,
                'uuid' => $data['id'] ?? Uuid::v4(),
                'file_number' => $this->nextFileNumber(),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'sex' => $data['sex'],
                'birth_date' => $data['birth_date'] ?? null,
                'birth_date_is_estimated' => $data['birth_date_is_estimated'] ?? false,
                'phone' => $data['phone'] ?? null,
                'status' => 'ACTIVE',
                'source' => $data['source'] ?? 'CLINIC',
                'attending_physician_id' => $attendingId,
                'validated_by' => $actorId,
                'validated_at' => $now,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            foreach ($contacts as $contact) {
                $this->children->insert('contacts', $id, $contact, $actorId);
            }
            foreach ($addresses as $address) {
                $this->children->insert('addresses', $id, $address, $actorId);
            }
            $patient = (array) $this->patients->findById($id);
            $this->journal->record('patient', (string) $patient['uuid'], ChangeJournal::UPSERT, $id);
            $this->audit->record('PATIENT_CREATED', $request, 'patient', (string) $patient['uuid'], null, $this->present($patient));
            return $this->presentDetailed($patient, $request, false);
        });
    }

    public function update(string $uuid, array $input, Request $request): array
    {
        $patient = $this->findOrFail($uuid);
        $this->assertOpen($patient);
        $rules = array_map(function (string $rule): string {
            return str_replace('required|', '', $rule);
        }, self::IDENTITY_RULES) + ['version' => 'nullable|integer'];
        $data = $this->validator->validate($input, $rules);
        $this->assertBirthDate($data);
        if (isset($data['version']) && $data['version'] !== (int) $patient['version']) {
            throw HttpException::conflict(
                'Ce dossier a été modifié entre-temps. Rechargez-le avant d\'enregistrer.',
                'VERSION_CONFLICT',
                ['version' => ['Version actuelle : ' . $patient['version'] . '.']]
            );
        }
        unset($data['version']);
        $actorId = self::auth($request)->userId();

        return $this->db->transaction(function () use ($patient, $data, $actorId, $request): array {
            $before = $this->present($patient);
            if ($data !== []) {
                $this->patients->update((int) $patient['id'], $data + ['updated_by' => $actorId]);
            }
            $fresh = (array) $this->patients->findById((int) $patient['id']);
            list($old, $new) = AuditLogger::changes($before, $this->present($fresh));
            if ($new !== []) {
                $this->journal->record('patient', (string) $fresh['uuid'], ChangeJournal::UPSERT, (int) $fresh['id']);
                $this->audit->record('PATIENT_UPDATED', $request, 'patient', (string) $fresh['uuid'], $old, $new);
            }
            return $this->presentDetailed($fresh, $request, false);
        });
    }

    public function assignAttendingPhysician(string $uuid, array $input, Request $request): array
    {
        $patient = $this->findOrFail($uuid);
        $this->assertOpen($patient);
        if (!array_key_exists('attending_physician_id', $input)) {
            throw new ValidationException(['attending_physician_id' => ['Ce champ est obligatoire (null pour retirer le médecin traitant).']]);
        }
        $data = $this->validator->validate($input, ['attending_physician_id' => 'nullable|uuid']);
        $physicianId = $data['attending_physician_id'] === null ? null : $this->resolvePhysician($data['attending_physician_id']);

        return $this->db->transaction(function () use ($patient, $physicianId, $request): array {
            $before = $this->present($patient);
            $this->patients->update((int) $patient['id'], ['attending_physician_id' => $physicianId, 'updated_by' => self::auth($request)->userId()]);
            $fresh = (array) $this->patients->findById((int) $patient['id']);
            $after = $this->present($fresh);
            // Le nouveau médecin traitant doit recevoir le dossier complet sur ses appareils.
            $this->snapshot->record((int) $fresh['id']);
            $this->audit->record('PATIENT_ATTENDING_CHANGED', $request, 'patient', (string) $fresh['uuid'], ['attending_physician' => $before['attending_physician']], ['attending_physician' => $after['attending_physician']]);
            return $after;
        });
    }

    public function medicalProfile(string $uuid, Request $request): array
    {
        $patient = $this->findOrFail($uuid);
        $this->policy->assertReadMedical(self::auth($request), $patient);
        $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'patient', $uuid, null, ['section' => 'medical-profile']);
        return $this->presentMedicalProfile($this->patients->medicalProfile((int) $patient['id']));
    }

    /**
     * @param string|null $profileUuid Identifiant généré par l'appareil, utilisé si le profil n'existe pas encore
     */
    public function updateMedicalProfile(string $uuid, array $input, Request $request, ?string $profileUuid = null): array
    {
        $patient = $this->findOrFail($uuid);
        $this->assertOpen($patient);
        $auth = self::auth($request);
        $this->policy->assertWriteMedical($auth, $patient);
        $data = $this->validator->validate($input, [
            'blood_group' => 'nullable|in:A+,A-,B+,B-,AB+,AB-,O+,O-',
            'observations' => 'nullable|string|max:5000',
        ]);

        return $this->db->transaction(function () use ($patient, $data, $auth, $request, $profileUuid): array {
            $existing = $this->patients->medicalProfile((int) $patient['id']);
            $before = $this->presentMedicalProfile($existing);
            $now = Clock::nowForDatabase();
            if ($existing === null) {
                $uuid = $profileUuid ?? Uuid::v4();
                $this->db->insert('patient_medical_profiles', $data + [
                    'uuid' => $uuid,
                    'patient_id' => (int) $patient['id'],
                    'created_by' => $auth->userId(),
                    'updated_by' => $auth->userId(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                $uuid = (string) $existing['uuid'];
                if ($data !== []) {
                    $this->db->update('patient_medical_profiles', $data + ['updated_by' => $auth->userId(), 'updated_at' => $now], 'id = ?', [(int) $existing['id']]);
                    $this->db->execute('UPDATE patient_medical_profiles SET version = version + 1 WHERE id = ?', [(int) $existing['id']]);
                }
            }
            $after = $this->presentMedicalProfile($this->patients->medicalProfile((int) $patient['id']));
            $this->journal->record('patient_medical_profile', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            list($old, $new) = AuditLogger::changes((array) $before, (array) $after);
            $this->audit->record('PATIENT_DATA_UPDATED', $request, 'patient_medical_profile', $uuid, $old, $new);
            return (array) $after;
        });
    }

    /**
     * Fusionne un dossier en doublon dans le dossier conservé : toutes ses données y sont rattachées.
     */
    public function merge(string $sourceUuid, array $input, Request $request): array
    {
        $data = $this->validator->validate($input, ['target_id' => 'required|uuid']);
        if ($data['target_id'] === $sourceUuid) {
            throw new ValidationException(['target_id' => ['Un dossier ne peut pas être fusionné avec lui-même.']]);
        }
        $source = $this->findOrFail($sourceUuid);
        $target = $this->findOrFail($data['target_id']);
        if ($source['status'] === 'MERGED') {
            throw HttpException::conflict('Ce dossier a déjà été fusionné.', 'PATIENT_CLOSED');
        }
        if ($target['status'] !== 'ACTIVE') {
            throw new ValidationException(['target_id' => ['Le dossier conservé doit être un dossier validé (actif).']]);
        }

        return $this->db->transaction(function () use ($source, $target, $request): array {
            $tables = $this->patients->reassignAllData((int) $source['id'], (int) $target['id']);
            $this->patients->update((int) $source['id'], [
                'status' => 'MERGED',
                'merged_into_id' => (int) $target['id'],
                'updated_by' => self::auth($request)->userId(),
            ]);
            if ($target['user_id'] === null && $source['user_id'] !== null) {
                $this->patients->update((int) $target['id'], ['user_id' => (int) $source['user_id']]);
            }
            $this->db->execute('UPDATE patients SET possible_duplicate_of = NULL WHERE possible_duplicate_of = ?', [(int) $source['id']]);

            $this->journal->record('patient', (string) $source['uuid'], ChangeJournal::UPSERT, (int) $target['id']);
            $this->journal->record('patient', (string) $target['uuid'], ChangeJournal::UPSERT, (int) $target['id']);
            $this->audit->record('PATIENT_MERGED', $request, 'patient', (string) $target['uuid'], ['merged_patient' => $source['uuid'], 'file_number' => $source['file_number']], ['tables' => $tables]);

            return $this->presentDetailed((array) $this->patients->findById((int) $target['id']), $request, false);
        });
    }

    /**
     * N° de dossier unique, ex. VSH-2026-000123 (séquence annuelle, dans la transaction appelante).
     */
    public function nextFileNumber(): string
    {
        $timezone = new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
        $year = Clock::now()->setTimezone($timezone)->format('Y');
        $prefix = strtoupper((string) $this->settings->get('patients.file_number_prefix', 'VSH'));
        return sprintf('%s-%s-%06d', $prefix, $year, $this->sequences->next('patient_file_number', $year));
    }

    public function findOrFail(string $uuid): array
    {
        $patient = $this->patients->findByUuid($uuid);
        if ($patient === null) {
            throw HttpException::notFound('Patient introuvable.');
        }
        return $patient;
    }

    /**
     * Dossier complet. Les données médicales ne sont incluses (et la consultation tracée) que si l'utilisateur y a droit.
     */
    public function presentDetailed(array $patient, Request $request, bool $auditMedicalView = true): array
    {
        $auth = self::auth($request);
        $item = $this->present($patient);
        $item['contacts'] = $this->children->rows('contacts', (int) $patient['id']);
        $item['addresses'] = $this->children->rows('addresses', (int) $patient['id']);
        if ($this->policy->canReadMedical($auth, $patient)) {
            $item['medical'] = $this->medicalSection($patient);
            if ($auditMedicalView && !$this->policy->owns($auth, $patient)) {
                $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'patient', (string) $patient['uuid'], null, ['section' => 'record']);
            }
        }
        return $item;
    }

    public function medicalSection(array $patient): array
    {
        $patientId = (int) $patient['id'];
        return [
            'profile' => $this->presentMedicalProfile($this->patients->medicalProfile($patientId)),
            'allergies' => $this->children->rows('allergies', $patientId),
            'medical_history' => $this->children->rows('medical-history', $patientId),
            'current_treatments' => $this->children->rows('current-treatments', $patientId),
        ];
    }

    public function present(array $patient): array
    {
        return $this->presentMany([$patient])[0];
    }

    public function presentMany(array $rows): array
    {
        $users = $this->patients->usersSummary(array_map(function (array $row) {
            return $row['attending_physician_id'] !== null ? (int) $row['attending_physician_id'] : null;
        }, $rows));
        $related = $this->patients->patientUuids(array_merge(
            array_map(function (array $row) {
                return $row['possible_duplicate_of'] !== null ? (int) $row['possible_duplicate_of'] : null;
            }, $rows),
            array_map(function (array $row) {
                return $row['merged_into_id'] !== null ? (int) $row['merged_into_id'] : null;
            }, $rows)
        ));
        $timezone = new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
        $today = Clock::now()->setTimezone($timezone);

        return array_map(function (array $row) use ($users, $related, $today): array {
            $attending = $row['attending_physician_id'] !== null ? ($users[(int) $row['attending_physician_id']] ?? null) : null;
            $age = null;
            if ($row['birth_date'] !== null) {
                $age = (new \DateTimeImmutable((string) $row['birth_date'], $today->getTimezone()))->diff($today)->y;
            }
            return [
                'id' => (string) $row['uuid'],
                'file_number' => $row['file_number'] !== null ? (string) $row['file_number'] : null,
                'first_name' => (string) $row['first_name'],
                'last_name' => (string) $row['last_name'],
                'sex' => (string) $row['sex'],
                'birth_date' => $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
                'birth_date_is_estimated' => (bool) $row['birth_date_is_estimated'],
                'age_years' => $age,
                'phone' => $row['phone'] !== null ? (string) $row['phone'] : null,
                'status' => (string) $row['status'],
                'source' => (string) $row['source'],
                'has_account' => $row['user_id'] !== null,
                'attending_physician' => $attending === null ? null : ['id' => $attending['uuid'], 'name' => $attending['name']],
                'possible_duplicate_of' => $row['possible_duplicate_of'] !== null ? ($related[(int) $row['possible_duplicate_of']] ?? null) : null,
                'merged_into' => $row['merged_into_id'] !== null ? ($related[(int) $row['merged_into_id']] ?? null) : null,
                'rejection_reason' => $row['rejection_reason'],
                'validated_at' => Clock::toIso($row['validated_at']),
                'version' => (int) $row['version'],
                'created_at' => Clock::toIso((string) $row['created_at']),
                'updated_at' => Clock::toIso((string) $row['updated_at']),
            ];
        }, $rows);
    }

    public function presentMedicalProfile(?array $profile): ?array
    {
        if ($profile === null) {
            return null;
        }
        return [
            'id' => (string) $profile['uuid'],
            'blood_group' => $profile['blood_group'],
            'observations' => $profile['observations'],
            'version' => (int) $profile['version'],
            'updated_at' => Clock::toIso((string) $profile['updated_at']),
        ];
    }

    private function resolvePhysician(string $userUuid): int
    {
        $row = $this->db->fetchOne(
            "SELECT u.id FROM users u JOIN staff_profiles sp ON sp.user_id = u.id
             WHERE u.uuid = ? AND u.account_type = 'STAFF' AND u.status = 'ACTIVE' AND sp.profession = 'MEDECIN'",
            [$userUuid]
        );
        if ($row === null) {
            throw new ValidationException(['attending_physician_id' => ['Le médecin traitant doit être un médecin actif de la clinique.']]);
        }
        return (int) $row['id'];
    }

    private function assertBirthDate(array $data): void
    {
        if (isset($data['birth_date']) && $data['birth_date'] > Clock::now()->format('Y-m-d')) {
            throw new ValidationException(['birth_date' => ['La date de naissance ne peut pas être dans le futur.']]);
        }
    }

    private function assertOpen(array $patient): void
    {
        if (in_array($patient['status'], ['MERGED', 'REJECTED'], true)) {
            throw HttpException::conflict('Ce dossier est clos et ne peut plus être modifié.', 'PATIENT_CLOSED');
        }
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
