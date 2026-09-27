<?php

declare(strict_types=1);

namespace Vsh\Modules\Treatments;

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
use Vsh\Modules\Consultations\ConsultationService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;

/**
 * Soins (rapport C6) : programmé (PLANIFIE) puis réalisé (REALISE), ou annulé (ANNULE).
 * Un soin réalisé ne s'annule plus (il a eu lieu) ; il sera facturé au tarif en vigueur à sa date.
 * Les observations sont des données médicales (droits médicaux, D-010).
 */
final class TreatmentService
{
    /** @var Database */
    private $db;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var ConsultationService */
    private $consultations;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        PatientRepository $patients,
        PatientPolicy $policy,
        ConsultationService $consultations,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->consultations = $consultations;
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
            'status' => 'nullable|in:PLANIFIE,REALISE,ANNULE',
            'mine' => 'nullable|boolean',
            'date' => 'nullable|date',
        ]);
        $where = ['t.deleted_at IS NULL'];
        $params = [];
        if (isset($data['patient_id'])) {
            $where[] = 'p.uuid = ?';
            $params[] = $data['patient_id'];
        }
        if (isset($data['status'])) {
            $where[] = 't.status = ?';
            $params[] = $data['status'];
        }
        if (!empty($data['mine'])) {
            $where[] = '(t.performed_by = ? OR t.created_by = ?)';
            array_push($params, $auth->userId(), $auth->userId());
        }
        if (isset($data['date'])) {
            $where[] = 'COALESCE(t.performed_at, t.scheduled_for) >= ? AND COALESCE(t.performed_at, t.scheduled_for) < ?';
            array_push($params, $data['date'] . ' 00:00:00', (new \DateTimeImmutable($data['date']))->modify('+1 day')->format('Y-m-d 00:00:00'));
        }
        $sql = ' FROM treatments t JOIN patients p ON p.id = t.patient_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT t.*' . $sql . ' ORDER BY COALESCE(t.performed_at, t.scheduled_for) DESC, t.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row) use ($auth): array {
            return $this->present($row, $auth);
        }, $rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        return $this->present($this->findOrFail($uuid), self::auth($request));
    }

    public function create(array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $this->assertCanPerform($auth);
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'consultation_id' => 'nullable|uuid',
            'treatment_type_id' => 'required|uuid',
            'status' => 'nullable|in:PLANIFIE,REALISE',
            'scheduled_for' => 'nullable|datetime',
            'performed_at' => 'nullable|datetime',
            'observations' => 'nullable|string|max:5000',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        if ($patient['status'] !== 'ACTIVE') {
            throw HttpException::conflict('Le dossier du patient doit être validé.', 'PATIENT_NOT_ACTIVE');
        }
        $consultationId = null;
        if (isset($data['consultation_id'])) {
            $consultation = $this->consultations->findOrFail($data['consultation_id']);
            if ((int) $consultation['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(['consultation_id' => ['Cette consultation concerne un autre patient.']]);
            }
            $this->consultations->assertOpen($consultation);
            $consultationId = (int) $consultation['id'];
        }
        $type = $this->activeType($data['treatment_type_id']);
        $status = $data['status'] ?? 'REALISE';
        if ($status === 'PLANIFIE' && !isset($data['scheduled_for'])) {
            throw new ValidationException(['scheduled_for' => ['Indiquez la date prévue du soin.']]);
        }
        $performedAt = null;
        if ($status === 'REALISE') {
            $performedAt = $data['performed_at'] ?? Clock::nowForDatabase();
            ConsultationService::assertNotFuture($performedAt, 'performed_at');
        }
        if ($uuid !== null && $this->db->fetchValue('SELECT id FROM treatments WHERE uuid = ?', [$uuid]) !== null) {
            throw HttpException::conflict('Ce soin existe déjà.', 'ALREADY_EXISTS');
        }

        return $this->db->transaction(function () use ($uuid, $patient, $consultationId, $type, $status, $data, $performedAt, $auth, $request): array {
            $uuid = $uuid ?? Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('treatments', [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'consultation_id' => $consultationId,
                'treatment_type_id' => (int) $type['id'],
                'status' => $status,
                'scheduled_for' => $data['scheduled_for'] ?? null,
                'performed_by' => $status === 'REALISE' ? $auth->userId() : null,
                'performed_at' => $performedAt,
                'observations' => $data['observations'] ?? null,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->journal->record('treatment', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record($status === 'REALISE' ? 'TREATMENT_PERFORMED' : 'TREATMENT_PLANNED', $request, 'treatment', $uuid, null, [
                'patient_id' => $patient['uuid'],
                'type' => $type['code'],
            ]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM treatments WHERE id = ?', [$id]), $auth);
        });
    }

    /**
     * Modifie un soin programmé (date prévue, observations) ou les observations d'un soin réalisé.
     */
    public function update(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $this->assertCanPerform($auth);
        $treatment = $this->findOrFail($uuid);
        if ($treatment['status'] === 'ANNULE') {
            throw HttpException::conflict('Ce soin a été annulé.', 'INVALID_TRANSITION');
        }
        $data = $this->validator->validate($input, [
            'scheduled_for' => 'nullable|datetime',
            'observations' => 'nullable|string|max:5000',
        ]);
        if (isset($data['scheduled_for']) && $treatment['status'] !== 'PLANIFIE') {
            throw new ValidationException(['scheduled_for' => ['Un soin réalisé n\'a plus de date prévue.']]);
        }
        $this->policy->assertWriteMedical($auth, (array) $this->patients->findById((int) $treatment['patient_id']));
        return $this->write($treatment, $data, 'TREATMENT_UPDATED', $auth, $request);
    }

    public function perform(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $this->assertCanPerform($auth);
        $data = $this->validator->validate($input, [
            'performed_at' => 'nullable|datetime',
            'observations' => 'nullable|string|max:5000',
        ]);
        $performedAt = $data['performed_at'] ?? Clock::nowForDatabase();
        ConsultationService::assertNotFuture($performedAt, 'performed_at');

        return $this->db->transaction(function () use ($uuid, $data, $performedAt, $auth, $request): array {
            $treatment = $this->lock($uuid);
            if ($treatment['status'] !== 'PLANIFIE') {
                throw HttpException::conflict('Seul un soin programmé peut être marqué comme réalisé.', 'INVALID_TRANSITION');
            }
            $changes = ['status' => 'REALISE', 'performed_at' => $performedAt, 'performed_by' => $auth->userId()];
            if (isset($data['observations'])) {
                $changes['observations'] = $data['observations'];
            }
            return $this->write($treatment, $changes, 'TREATMENT_PERFORMED', $auth, $request);
        });
    }

    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $this->assertCanPerform($auth);
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $treatment = $this->lock($uuid);
            if ($treatment['status'] !== 'PLANIFIE') {
                throw HttpException::conflict('Seul un soin programmé peut être annulé : un soin réalisé a eu lieu.', 'INVALID_TRANSITION');
            }
            return $this->write($treatment, ['status' => 'ANNULE', 'cancel_reason' => $data['reason']], 'TREATMENT_CANCELLED', $auth, $request);
        });
    }

    public function findOrFail(string $uuid): array
    {
        $treatment = $this->db->fetchOne('SELECT * FROM treatments WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($treatment === null) {
            throw HttpException::notFound('Soin introuvable.');
        }
        return $treatment;
    }

    public function present(array $row, AuthContext $auth): array
    {
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $type = (array) $this->db->fetchOne('SELECT uuid, code, label FROM treatment_types WHERE id = ?', [(int) $row['treatment_type_id']]);
        $performer = $row['performed_by'] !== null
            ? $this->db->fetchOne('SELECT uuid, first_name, last_name FROM users WHERE id = ?', [(int) $row['performed_by']])
            : null;
        $consultationUuid = $row['consultation_id'] !== null
            ? $this->db->fetchValue('SELECT uuid FROM consultations WHERE id = ?', [(int) $row['consultation_id']])
            : null;
        $item = [
            'id' => (string) $row['uuid'],
            'patient_id' => (string) $patient['uuid'],
            'consultation_id' => $consultationUuid !== null ? (string) $consultationUuid : null,
            'treatment_type' => ['id' => (string) $type['uuid'], 'code' => (string) $type['code'], 'label' => (string) $type['label']],
            'status' => (string) $row['status'],
            'scheduled_for' => Clock::toIso($row['scheduled_for']),
            'performed_at' => Clock::toIso($row['performed_at']),
            'performed_by' => $performer === null ? null : ['id' => (string) $performer['uuid'], 'name' => $performer['first_name'] . ' ' . $performer['last_name']],
            'cancel_reason' => $row['cancel_reason'],
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if ($this->policy->canReadMedical($auth, $patient)) {
            $item['observations'] = $row['observations'];
        }
        return $item;
    }

    private function write(array $treatment, array $changes, string $action, AuthContext $auth, Request $request): array
    {
        return $this->db->transaction(function () use ($treatment, $changes, $action, $auth, $request): array {
            if ($changes !== []) {
                $this->db->update('treatments', $changes + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $treatment['id']]);
                $this->db->execute('UPDATE treatments SET version = version + 1 WHERE id = ?', [(int) $treatment['id']]);
                $this->journal->record('treatment', (string) $treatment['uuid'], ChangeJournal::UPSERT, (int) $treatment['patient_id']);
                $this->audit->record($action, $request, 'treatment', (string) $treatment['uuid'], null, ['fields' => array_keys($changes)]);
            }
            return $this->present((array) $this->db->fetchOne('SELECT * FROM treatments WHERE id = ?', [(int) $treatment['id']]), $auth);
        });
    }

    private function lock(string $uuid): array
    {
        $treatment = $this->db->fetchOne('SELECT * FROM treatments WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($treatment === null) {
            throw HttpException::notFound('Soin introuvable.');
        }
        return $treatment;
    }

    private function activeType(string $uuid): array
    {
        $type = $this->db->fetchOne('SELECT id, code, active FROM treatment_types WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($type === null || !(bool) $type['active']) {
            throw new ValidationException(['treatment_type_id' => ['Type de soin introuvable ou désactivé.']]);
        }
        return $type;
    }

    private function assertCanPerform(AuthContext $auth): void
    {
        if (!$auth->can('treatments.perform')) {
            throw HttpException::forbidden();
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
