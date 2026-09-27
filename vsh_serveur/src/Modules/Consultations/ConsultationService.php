<?php

declare(strict_types=1);

namespace Vsh\Modules\Consultations;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Reference\ReferenceService;
use Vsh\Modules\Settings\SettingsService;

/**
 * Consultation (rapport C4) : OUVERTE → EN_COURS → CLOTUREE, ou ANNULEE.
 *
 * - Le contenu clinique (motif, symptômes, examen, conclusion, diagnostics, notes, constantes, soins)
 *   n'est visible qu'avec les droits médicaux (D-010) ; l'accueil ne voit que les métadonnées.
 * - Une consultation clôturée n'est plus modifiée : toute correction est une note « ADDENDUM ».
 * - Le journal d'audit trace les champs modifiés, jamais leur contenu clinique.
 * - Aucune règle clinique n'est codée : le logiciel enregistre, le professionnel décide.
 */
final class ConsultationService
{
    public const OPEN_STATUSES = ['OUVERTE', 'EN_COURS'];
    private const CLINICAL_FIELDS = ['chief_complaint', 'symptoms', 'clinical_exam', 'conclusion'];

    /** @var Database */
    private $db;

    /** @var ConsultationRepository */
    private $consultations;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var ReferenceService */
    private $references;

    /** @var SettingsService */
    private $settings;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        ConsultationRepository $consultations,
        PatientRepository $patients,
        PatientPolicy $policy,
        ReferenceService $references,
        SettingsService $settings,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->consultations = $consultations;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->references = $references;
        $this->settings = $settings;
        $this->journal = $journal;
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
            'patient_id' => 'nullable|uuid',
            'status' => 'nullable|in:OUVERTE,EN_COURS,CLOTUREE,ANNULEE',
            'type' => 'nullable|in:CLINIQUE,DOMICILE,SUIVI,URGENCE',
            'mine' => 'nullable|boolean',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        $filters = [];
        if (isset($data['patient_id'])) {
            $patient = $this->patients->findByUuid($data['patient_id']);
            if ($patient === null) {
                return [[], 0];
            }
            $filters['patient_id'] = (int) $patient['id'];
        }
        if (isset($data['status'])) {
            $filters['status'] = $data['status'];
        }
        if (isset($data['type'])) {
            $filters['consultation_type'] = $data['type'];
        }
        if (!empty($data['mine'])) {
            $filters['practitioner_id'] = $auth->userId();
        }
        if (isset($data['from'])) {
            $filters['from'] = $data['from'] . ' 00:00:00';
        }
        if (isset($data['to'])) {
            $filters['to'] = (new \DateTimeImmutable($data['to']))->modify('+1 day')->format('Y-m-d 00:00:00');
        }
        list($rows, $total) = $this->consultations->paginate($filters, $pagination->perPage(), $pagination->offset());
        return [$this->presentMany($rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        return $this->detailed($this->findOrFail($uuid), $request, true);
    }

    public function create(array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'consultation_type' => 'required|in:CLINIQUE,DOMICILE,SUIVI,URGENCE',
            'parent_consultation_id' => 'nullable|uuid',
            'appointment_id' => 'nullable|uuid',
            'service_id' => 'nullable|uuid',
            'practitioner_id' => 'nullable|uuid',
            'chief_complaint' => 'nullable|string|max:5000',
            'started_at' => 'nullable|datetime',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        if ($patient['status'] !== 'ACTIVE') {
            throw HttpException::conflict('Le dossier du patient doit être validé avant une consultation.', 'PATIENT_NOT_ACTIVE');
        }
        $type = $data['consultation_type'];
        if ($type === 'DOMICILE') {
            throw new ValidationException(['consultation_type' => ['Une consultation à domicile s\'ouvre depuis la visite à domicile correspondante.']]);
        }
        if ($type === 'URGENCE' && !$this->settings->get('consultations.urgent_enabled', true)) {
            throw new ValidationException(['consultation_type' => ['Les consultations d\'urgence ne sont pas activées.']]);
        }
        $parentId = null;
        if (isset($data['parent_consultation_id'])) {
            $parent = $this->consultations->findByUuid($data['parent_consultation_id']);
            if ($parent === null || (int) $parent['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(['parent_consultation_id' => ['Consultation d\'origine introuvable pour ce patient.']]);
            }
            $parentId = (int) $parent['id'];
        } elseif ($type === 'SUIVI') {
            throw new ValidationException(['parent_consultation_id' => ['Indiquez la consultation dont celle-ci est le suivi.']]);
        }
        $appointment = null;
        if (isset($data['appointment_id'])) {
            $appointment = $this->db->fetchOne('SELECT * FROM appointments WHERE uuid = ? AND deleted_at IS NULL', [$data['appointment_id']]);
            if ($appointment === null || (int) $appointment['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(['appointment_id' => ['Rendez-vous introuvable pour ce patient.']]);
            }
            if (!in_array($appointment['status'], ['CONFIRME', 'DEPLACE'], true)) {
                throw HttpException::conflict('Ce rendez-vous n\'est pas confirmé ou a déjà donné lieu à une consultation.', 'INVALID_TRANSITION');
            }
        }
        $serviceId = null;
        if (isset($data['service_id'])) {
            $serviceId = $this->references->idFor('services', $data['service_id']);
            if ($serviceId === null) {
                throw new ValidationException(['service_id' => ['Service introuvable.']]);
            }
        }
        $practitionerId = $this->resolvePractitioner($data['practitioner_id'] ?? null, $auth);
        $startedAt = $data['started_at'] ?? Clock::nowForDatabase();
        self::assertNotFuture($startedAt, 'started_at');
        if ($uuid !== null && $this->consultations->uuidExists('consultations', $uuid)) {
            throw HttpException::conflict('Cette consultation existe déjà.', 'ALREADY_EXISTS');
        }

        if ($serviceId === null && $appointment !== null) {
            $serviceId = (int) $appointment['service_id'];
        }

        return $this->db->transaction(function () use ($uuid, $patient, $type, $parentId, $appointment, $serviceId, $practitionerId, $data, $startedAt, $auth, $request): array {
            $uuid = $uuid ?? Uuid::v4();
            $id = $this->consultations->create([
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'consultation_type' => $type,
                'parent_consultation_id' => $parentId,
                'appointment_id' => $appointment !== null ? (int) $appointment['id'] : null,
                'service_id' => $serviceId,
                'practitioner_id' => $practitionerId,
                'status' => 'OUVERTE',
                'chief_complaint' => $data['chief_complaint'] ?? null,
                'started_at' => $startedAt,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
            ]);
            $this->journal->record('consultation', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            if ($appointment !== null) {
                // Le rendez-vous est honoré : la consultation qui en découle est ouverte.
                $this->db->execute(
                    "UPDATE appointments SET status = 'HONORE', checked_in_at = COALESCE(checked_in_at, ?), updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?",
                    [Clock::nowForDatabase(), $auth->userId(), Clock::nowForDatabase(), (int) $appointment['id']]
                );
                $this->journal->record('appointment', (string) $appointment['uuid'], ChangeJournal::UPSERT, (int) $patient['id'], null,
                    $appointment['practitioner_id'] !== null ? (int) $appointment['practitioner_id'] : null);
            }
            $this->audit->record('CONSULTATION_OPENED', $request, 'consultation', $uuid, null, [
                'patient_id' => $patient['uuid'],
                'type' => $type,
            ]);
            return $this->detailed((array) $this->consultations->findById($id), $request, false);
        });
    }

    /**
     * Consultation DOMICILE ouverte au démarrage d'une visite à domicile (module Homecare), qui a
     * déjà vérifié les droits de l'équipe. Idempotente : renvoie la consultation existante de la visite.
     */
    public function openForHomecare(array $patient, int $homecareRequestId, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $existing = $this->db->fetchOne(
            "SELECT * FROM consultations WHERE homecare_request_id = ? AND status <> 'ANNULEE' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            [$homecareRequestId]
        );
        if ($existing !== null) {
            return $existing;
        }
        if ($uuid !== null && $this->consultations->uuidExists('consultations', $uuid)) {
            throw HttpException::conflict('Cette consultation existe déjà.', 'ALREADY_EXISTS');
        }
        $uuid = $uuid ?? Uuid::v4();
        $id = $this->consultations->create([
            'uuid' => $uuid,
            'patient_id' => (int) $patient['id'],
            'consultation_type' => 'DOMICILE',
            'homecare_request_id' => $homecareRequestId,
            'practitioner_id' => $auth->can('consultations.update') ? $auth->userId() : null,
            'status' => 'OUVERTE',
            'started_at' => Clock::nowForDatabase(),
            'created_by' => $auth->userId(),
            'updated_by' => $auth->userId(),
        ]);
        $this->journal->record('consultation', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
        $this->audit->record('CONSULTATION_OPENED', $request, 'consultation', $uuid, null, [
            'patient_id' => $patient['uuid'],
            'type' => 'DOMICILE',
        ]);
        return (array) $this->consultations->findById($id);
    }

    public function update(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $consultation = $this->findOrFail($uuid);
        $this->assertOpen($consultation);
        $patient = $this->patientOf($consultation);
        $this->policy->assertWriteMedical($auth, $patient);
        $data = $this->validator->validate($input, [
            'chief_complaint' => 'nullable|string|max:5000',
            'symptoms' => 'nullable|string|max:5000',
            'clinical_exam' => 'nullable|string|max:5000',
            'conclusion' => 'nullable|string|max:5000',
            'practitioner_id' => 'nullable|uuid',
            'service_id' => 'nullable|uuid',
            'version' => 'nullable|integer',
        ]);
        if (isset($data['version']) && $data['version'] !== (int) $consultation['version']) {
            throw HttpException::conflict(
                'Cette consultation a été modifiée entre-temps. Rechargez-la avant d\'enregistrer.',
                'VERSION_CONFLICT',
                ['version' => ['Version actuelle : ' . $consultation['version'] . '.']]
            );
        }
        unset($data['version']);
        if (array_key_exists('practitioner_id', $data)) {
            $data['practitioner_id'] = $data['practitioner_id'] === null ? null : $this->resolvePractitioner($data['practitioner_id'], $auth);
        }
        if (array_key_exists('service_id', $data) && $data['service_id'] !== null) {
            $serviceId = $this->references->idFor('services', $data['service_id']);
            if ($serviceId === null) {
                throw new ValidationException(['service_id' => ['Service introuvable.']]);
            }
            $data['service_id'] = $serviceId;
        }
        if ($data === []) {
            return $this->detailed($consultation, $request, false);
        }

        return $this->db->transaction(function () use ($consultation, $data, $auth, $request): array {
            $changes = $data + ['updated_by' => $auth->userId()];
            if ($consultation['status'] === 'OUVERTE' && array_intersect(array_keys($data), self::CLINICAL_FIELDS) !== []) {
                $changes['status'] = 'EN_COURS';
            }
            $this->consultations->update((int) $consultation['id'], $changes);
            $this->journal->record('consultation', (string) $consultation['uuid'], ChangeJournal::UPSERT, (int) $consultation['patient_id']);
            // Seuls les noms des champs sont audités, jamais le contenu clinique.
            $this->audit->record('CONSULTATION_UPDATED', $request, 'consultation', (string) $consultation['uuid'], null, ['fields' => array_keys($data)]);
            return $this->detailed((array) $this->consultations->findById((int) $consultation['id']), $request, false);
        });
    }

    public function close(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        return $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $consultation = $this->lockOpen($uuid);
            $this->consultations->update((int) $consultation['id'], [
                'status' => 'CLOTUREE',
                'closed_at' => Clock::nowForDatabase(),
                'closed_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
            ]);
            $this->journal->record('consultation', $uuid, ChangeJournal::UPSERT, (int) $consultation['patient_id']);
            $this->audit->record('CONSULTATION_CLOSED', $request, 'consultation', $uuid);
            return $this->detailed((array) $this->consultations->findById((int) $consultation['id']), $request, false);
        });
    }

    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $consultation = $this->lockOpen($uuid);
            $this->consultations->update((int) $consultation['id'], [
                'status' => 'ANNULEE',
                'cancel_reason' => $data['reason'],
                'updated_by' => $auth->userId(),
            ]);
            $this->journal->record('consultation', $uuid, ChangeJournal::UPSERT, (int) $consultation['patient_id']);
            $this->audit->record('CONSULTATION_CANCELLED', $request, 'consultation', $uuid, null, ['reason' => $data['reason']]);
            return $this->detailed((array) $this->consultations->findById((int) $consultation['id']), $request, false);
        });
    }

    public function addDiagnosis(string $consultationUuid, array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $consultation = $this->findOrFail($consultationUuid);
        $this->assertOpen($consultation);
        $this->policy->assertWriteMedical($auth, $this->patientOf($consultation));
        $data = $this->validator->validate($input, [
            'label' => 'required|string|max:255',
            'icd10_code' => ['nullable', 'string', 'regex:/^[A-Z][0-9]{2}(\.[0-9A-Z]{1,4})?$/'],
            'diagnosis_kind' => 'nullable|in:PRINCIPAL,SECONDAIRE',
            'certainty' => 'nullable|in:CONFIRME,PROBABLE,SUSPECTE',
        ]);

        return $this->db->transaction(function () use ($consultation, $data, $auth, $request, $uuid): array {
            $uuid = $uuid ?? Uuid::v4();
            $id = $this->consultations->insert('consultation_diagnoses', [
                'uuid' => $uuid,
                'consultation_id' => (int) $consultation['id'],
                'icd10_code' => $data['icd10_code'] ?? null,
                'label' => $data['label'],
                'diagnosis_kind' => $data['diagnosis_kind'] ?? 'PRINCIPAL',
                'certainty' => $data['certainty'] ?? 'CONFIRME',
                'recorded_by' => $auth->userId(),
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
            ]);
            $this->touchInProgress($consultation, $auth);
            $this->journal->record('consultation_diagnosis', $uuid, ChangeJournal::UPSERT, (int) $consultation['patient_id']);
            $this->audit->record('DIAGNOSIS_RECORDED', $request, 'consultation', (string) $consultation['uuid']);
            return $this->presentDiagnosis((array) $this->db->fetchOne('SELECT * FROM consultation_diagnoses WHERE id = ?', [$id]), (string) $consultation['uuid']);
        });
    }

    public function deleteDiagnosis(string $diagnosisUuid, Request $request): void
    {
        $auth = self::auth($request);
        $diagnosis = $this->consultations->findRecord('consultation_diagnoses', $diagnosisUuid);
        if ($diagnosis === null) {
            return;
        }
        $consultation = (array) $this->consultations->findById((int) $diagnosis['consultation_id']);
        $this->assertOpen($consultation);
        $this->policy->assertWriteMedical($auth, $this->patientOf($consultation));
        $this->db->transaction(function () use ($diagnosis, $consultation, $auth, $request): void {
            $this->consultations->softDelete('consultation_diagnoses', (int) $diagnosis['id'], $auth->userId());
            $this->journal->record('consultation_diagnosis', (string) $diagnosis['uuid'], ChangeJournal::DELETE, (int) $consultation['patient_id']);
            $this->audit->record('DIAGNOSIS_DELETED', $request, 'consultation', (string) $consultation['uuid']);
        });
    }

    /**
     * Note clinique. Après clôture, elle est enregistrée comme ADDENDUM (la consultation n'est pas modifiée).
     */
    public function addNote(string $consultationUuid, array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $consultation = $this->findOrFail($consultationUuid);
        if ($consultation['status'] === 'ANNULEE') {
            throw HttpException::conflict('Cette consultation a été annulée.', 'CONSULTATION_CLOSED');
        }
        $this->policy->assertWriteMedical($auth, $this->patientOf($consultation));
        $data = $this->validator->validate($input, ['content' => 'required|string|max:10000']);
        $kind = $consultation['status'] === 'CLOTUREE' ? 'ADDENDUM' : 'NOTE';

        return $this->db->transaction(function () use ($consultation, $data, $kind, $auth, $request, $uuid): array {
            $uuid = $uuid ?? Uuid::v4();
            $id = $this->consultations->insert('consultation_notes', [
                'uuid' => $uuid,
                'consultation_id' => (int) $consultation['id'],
                'author_id' => $auth->userId(),
                'note_kind' => $kind,
                'content' => $data['content'],
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
            ]);
            if ($kind === 'NOTE') {
                $this->touchInProgress($consultation, $auth);
            }
            $this->journal->record('consultation_note', $uuid, ChangeJournal::UPSERT, (int) $consultation['patient_id']);
            $this->audit->record($kind === 'ADDENDUM' ? 'CONSULTATION_ADDENDUM' : 'CONSULTATION_NOTE', $request, 'consultation', (string) $consultation['uuid']);
            return $this->presentNote((array) $this->db->fetchOne('SELECT * FROM consultation_notes WHERE id = ?', [$id]), (string) $consultation['uuid']);
        });
    }

    public function findOrFail(string $uuid): array
    {
        $consultation = $this->consultations->findByUuid($uuid);
        if ($consultation === null) {
            throw HttpException::notFound('Consultation introuvable.');
        }
        return $consultation;
    }

    public function patientOf(array $consultation): array
    {
        return (array) $this->patients->findById((int) $consultation['patient_id']);
    }

    public function canReadClinical(AuthContext $auth, array $consultation): bool
    {
        return $this->policy->canReadMedical($auth, $this->patientOf($consultation));
    }

    public function assertOpen(array $consultation): void
    {
        if (!in_array($consultation['status'], self::OPEN_STATUSES, true)) {
            throw HttpException::conflict(
                'Cette consultation est clôturée ou annulée : ajoutez un addendum si nécessaire.',
                'CONSULTATION_CLOSED'
            );
        }
    }

    /**
     * Passe la consultation « en cours » à la première saisie clinique.
     */
    public function touchInProgress(array $consultation, AuthContext $auth): void
    {
        if ($consultation['status'] === 'OUVERTE') {
            $this->consultations->update((int) $consultation['id'], ['status' => 'EN_COURS', 'updated_by' => $auth->userId()]);
            $this->journal->record('consultation', (string) $consultation['uuid'], ChangeJournal::UPSERT, (int) $consultation['patient_id']);
        }
    }

    /**
     * Consultation avec, si l'utilisateur y a droit, son contenu clinique et ses éléments.
     */
    public function detailed(array $consultation, Request $request, bool $auditView): array
    {
        $auth = self::auth($request);
        $item = $this->presentMany([$consultation])[0];
        if (!$this->canReadClinical($auth, $consultation)) {
            return $item;
        }
        $id = (int) $consultation['id'];
        $uuid = (string) $consultation['uuid'];
        $item += $this->clinicalFields($consultation);
        $item['vitals'] = array_map(function (array $row) use ($uuid, $item): array {
            return VitalSignService::present($row, $item['patient_id'], $uuid);
        }, $this->consultations->vitalsForConsultation($id));
        $item['diagnoses'] = array_map(function (array $row) use ($uuid): array {
            return $this->presentDiagnosis($row, $uuid);
        }, $this->consultations->diagnoses($id));
        $item['notes'] = array_map(function (array $row) use ($uuid): array {
            return $this->presentNote($row, $uuid);
        }, $this->consultations->notes($id));
        $item['treatments'] = array_map(function (array $row): array {
            return [
                'id' => (string) $row['uuid'],
                'type' => ['code' => (string) $row['type_code'], 'label' => (string) $row['type_label']],
                'status' => (string) $row['status'],
                'scheduled_for' => Clock::toIso($row['scheduled_for']),
                'performed_at' => Clock::toIso($row['performed_at']),
                'observations' => $row['observations'],
            ];
        }, $this->consultations->treatments($id));
        if ($auditView && !$auth->isPatient()) {
            $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'consultation', $uuid);
        }
        return $item;
    }

    /**
     * Représentation sans contenu clinique (listes, accueil).
     */
    public function presentMany(array $rows): array
    {
        $people = $this->consultations->people(array_merge(
            array_column($rows, 'practitioner_id'),
            array_column($rows, 'closed_by')
        ));
        $patients = $this->consultations->patientSummaries(array_column($rows, 'patient_id'));
        $parents = $this->consultations->uuids('consultations', array_column($rows, 'parent_consultation_id'));
        $services = $this->consultations->uuids('services', array_column($rows, 'service_id'));

        return array_map(function (array $row) use ($people, $patients, $parents, $services): array {
            return [
                'id' => (string) $row['uuid'],
                'patient_id' => isset($patients[(int) $row['patient_id']]) ? $patients[(int) $row['patient_id']]['id'] : null,
                'patient' => $patients[(int) $row['patient_id']] ?? null,
                'consultation_type' => (string) $row['consultation_type'],
                'status' => (string) $row['status'],
                'practitioner' => $row['practitioner_id'] !== null ? ($people[(int) $row['practitioner_id']] ?? null) : null,
                'service_id' => $row['service_id'] !== null ? ($services[(int) $row['service_id']] ?? null) : null,
                'parent_consultation_id' => $row['parent_consultation_id'] !== null ? ($parents[(int) $row['parent_consultation_id']] ?? null) : null,
                'started_at' => Clock::toIso((string) $row['started_at']),
                'closed_at' => Clock::toIso($row['closed_at']),
                'closed_by' => $row['closed_by'] !== null ? ($people[(int) $row['closed_by']] ?? null) : null,
                'version' => (int) $row['version'],
                'created_at' => Clock::toIso((string) $row['created_at']),
                'updated_at' => Clock::toIso((string) $row['updated_at']),
            ];
        }, $rows);
    }

    public function clinicalFields(array $consultation): array
    {
        return [
            'chief_complaint' => $consultation['chief_complaint'],
            'symptoms' => $consultation['symptoms'],
            'clinical_exam' => $consultation['clinical_exam'],
            'conclusion' => $consultation['conclusion'],
            'cancel_reason' => $consultation['cancel_reason'],
        ];
    }

    public function presentDiagnosis(array $row, string $consultationUuid): array
    {
        return [
            'id' => (string) $row['uuid'],
            'consultation_id' => $consultationUuid,
            'label' => (string) $row['label'],
            'icd10_code' => $row['icd10_code'],
            'diagnosis_kind' => (string) $row['diagnosis_kind'],
            'certainty' => (string) $row['certainty'],
            'version' => (int) $row['version'],
            'created_at' => Clock::toIso((string) $row['created_at']),
        ];
    }

    public function presentNote(array $row, string $consultationUuid): array
    {
        $author = $this->consultations->people([(int) $row['author_id']]);
        return [
            'id' => (string) $row['uuid'],
            'consultation_id' => $consultationUuid,
            'note_kind' => (string) $row['note_kind'],
            'content' => (string) $row['content'],
            'author' => $author[(int) $row['author_id']] ?? null,
            'version' => (int) $row['version'],
            'created_at' => Clock::toIso((string) $row['created_at']),
        ];
    }

    private function lockOpen(string $uuid): array
    {
        $consultation = $this->consultations->findByUuid($uuid, true);
        if ($consultation === null) {
            throw HttpException::notFound('Consultation introuvable.');
        }
        $this->assertOpen($consultation);
        return $consultation;
    }

    private function resolvePractitioner(?string $userUuid, AuthContext $auth): ?int
    {
        if ($userUuid === null) {
            return $auth->can('consultations.update') ? $auth->userId() : null;
        }
        $id = $this->consultations->activeStaffId($userUuid);
        if ($id === null) {
            throw new ValidationException(['practitioner_id' => ['Praticien introuvable ou inactif.']]);
        }
        return $id;
    }

    public static function assertNotFuture(string $dateTime, string $field): void
    {
        if ($dateTime > Clock::now()->modify('+5 minutes')->format('Y-m-d H:i:s')) {
            throw new ValidationException([$field => ['La date ne peut pas être dans le futur.']]);
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
