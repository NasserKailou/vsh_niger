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
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Consultations\ConsultationService;
use Vsh\Modules\Notifications\NotificationService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;

/**
 * Ordonnances (rapport C8) :
 *   BROUILLON ──sign (prescripteur)──▶ SIGNEE
 *   BROUILLON / SIGNEE ──cancel (motif)──▶ ANNULEE
 *
 * - Rédaction libre ou à partir d'un modèle ACTIF, dont les lignes sont copiées et restent modifiables.
 * - Alertes d'allergie informatives, jamais bloquantes : le prescripteur décide.
 * - Une ordonnance signée n'est plus modifiable ; le patient ne voit que les ordonnances signées.
 */
final class PrescriptionService
{
    /** @var Database */
    private $db;

    /** @var PrescriptionItems */
    private $items;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var ConsultationService */
    private $consultations;

    /** @var NotificationService */
    private $notifications;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        PrescriptionItems $items,
        PatientRepository $patients,
        PatientPolicy $policy,
        ConsultationService $consultations,
        NotificationService $notifications,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator,
        PrescriptionPdf $pdf
    ) {
        $this->db = $db;
        $this->items = $items;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->consultations = $consultations;
        $this->notifications = $notifications;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->pdf = $pdf;
    }

    /** @var PrescriptionPdf */
    private $pdf;

    /**
     * Ordonnance SIGNÉE au format PDF : droits médicaux sur le dossier requis (D-010), export tracé.
     *
     * @return array{filename: string, content: string}
     */
    public function pdf(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $prescription = $this->findOrFail($uuid);
        $patient = (array) $this->patients->findById((int) $prescription['patient_id']);
        if ($auth->isPatient() || !$this->policy->canReadMedical($auth, $patient)) {
            throw HttpException::forbidden();
        }
        return $this->document($prescription, $patient, $request, 'staff');
    }

    /**
     * Ordonnance signée d'un dossier rattaché au compte patient.
     *
     * @return array{filename: string, content: string}
     */
    public function pdfForOwnPatient(string $patientUuid, string $prescriptionUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $prescription = $this->db->fetchOne(
            'SELECT * FROM prescriptions WHERE uuid = ? AND patient_id = ? AND deleted_at IS NULL',
            [$prescriptionUuid, (int) $patient['id']]
        );
        if ($prescription === null || $prescription['status'] !== 'SIGNEE') {
            throw HttpException::notFound('Ordonnance introuvable.');
        }
        return $this->document($prescription, $patient, $request, 'patient');
    }

    private function document(array $prescription, array $patient, Request $request, string $by): array
    {
        if ($prescription['status'] !== 'SIGNEE') {
            throw HttpException::conflict('Seule une ordonnance signée peut être imprimée.', 'NOT_SIGNED');
        }
        $prescriber = (array) $this->db->fetchOne('SELECT id, first_name, last_name FROM users WHERE id = ?', [(int) $prescription['prescriber_id']]);
        $profile = $this->db->fetchOne('SELECT profession, speciality, license_number FROM staff_profiles WHERE user_id = ?', [(int) $prescription['prescriber_id']]);
        $content = $this->pdf->render($prescription, $this->itemRows((int) $prescription['id']), $patient, $prescriber, $profile);
        $this->audit->record('PRESCRIPTION_EXPORTED', $request, 'prescription', (string) $prescription['uuid'], null, ['format' => 'pdf', 'by' => $by]);
        return ['filename' => 'ordonnance-' . substr((string) $prescription['uuid'], 0, 8) . '.pdf', 'content' => $content];
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($query, [
            'patient_id' => 'nullable|uuid',
            'status' => 'nullable|in:BROUILLON,SIGNEE,ANNULEE',
            'mine' => 'nullable|boolean',
        ]);
        $where = ['r.deleted_at IS NULL'];
        $params = [];
        if (isset($data['patient_id'])) {
            $where[] = 'p.uuid = ?';
            $params[] = $data['patient_id'];
        }
        if (isset($data['status'])) {
            $where[] = 'r.status = ?';
            $params[] = $data['status'];
        }
        if (!empty($data['mine'])) {
            $where[] = 'r.prescriber_id = ?';
            $params[] = $auth->userId();
        }
        $sql = ' FROM prescriptions r JOIN patients p ON p.id = r.patient_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT r.*' . $sql . ' ORDER BY r.created_at DESC, r.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row) use ($auth): array {
            return $this->present($row, $auth, false);
        }, $rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $item = $this->present($this->findOrFail($uuid), $auth, true);
        if (isset($item['items'])) {
            $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'prescription', $uuid);
        }
        return $item;
    }

    /**
     * Ordonnances SIGNÉES d'un dossier rattaché au compte patient.
     */
    public function forOwnPatient(string $patientUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM prescriptions WHERE patient_id = ? AND status = 'SIGNEE' AND deleted_at IS NULL ORDER BY signed_at DESC",
            [(int) $patient['id']]
        );
        return array_map(function (array $row) use ($auth): array {
            return $this->present($row, $auth, true);
        }, $rows);
    }

    public function create(array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        self::require($auth, 'prescriptions.write');
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'consultation_id' => 'nullable|uuid',
            'template_id' => 'nullable|uuid',
            'notes' => 'nullable|string|max:2000',
            'items' => 'nullable|array',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        if ($patient['status'] !== 'ACTIVE') {
            throw HttpException::conflict('Le dossier du patient doit être validé.', 'PATIENT_NOT_ACTIVE');
        }
        $this->policy->assertWriteMedical($auth, $patient);

        $consultationId = null;
        if (isset($data['consultation_id'])) {
            $consultation = $this->consultations->findOrFail($data['consultation_id']);
            if ((int) $consultation['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(['consultation_id' => ['Cette consultation concerne un autre patient.']]);
            }
            $this->consultations->assertOpen($consultation);
            $consultationId = (int) $consultation['id'];
        }
        $template = null;
        if (isset($data['template_id'])) {
            $template = $this->db->fetchOne('SELECT * FROM prescription_templates WHERE uuid = ? AND deleted_at IS NULL', [$data['template_id']]);
            if ($template === null || $template['status'] !== 'ACTIF') {
                throw new ValidationException(['template_id' => ['Modèle introuvable ou non approuvé.']]);
            }
        }
        if (isset($data['items'])) {
            $rows = $this->items->validate((array) $data['items']);
        } else {
            $rows = $template !== null ? $this->items->fromTemplate((int) $template['id']) : [];
        }
        if ($rows === []) {
            throw new ValidationException(['items' => ['Ajoutez au moins un médicament.']]);
        }
        if ($uuid !== null && $this->db->fetchValue('SELECT id FROM prescriptions WHERE uuid = ?', [$uuid]) !== null) {
            throw HttpException::conflict('Cette ordonnance existe déjà.', 'ALREADY_EXISTS');
        }

        return $this->db->transaction(function () use ($uuid, $patient, $consultationId, $template, $data, $rows, $auth, $request): array {
            $uuid = $uuid ?? Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('prescriptions', [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'consultation_id' => $consultationId,
                'prescriber_id' => $auth->userId(),
                'template_id' => $template !== null ? (int) $template['id'] : null,
                'template_version' => $template !== null ? (int) $template['template_version'] : null,
                'status' => 'BROUILLON',
                'notes' => $data['notes'] ?? null,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertItems($id, $rows, $auth);
            $this->journal->record('prescription', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record('PRESCRIPTION_CREATED', $request, 'prescription', $uuid, null, [
                'items' => count($rows),
                'template' => $template !== null ? $template['uuid'] : null,
            ]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescriptions WHERE id = ?', [$id]), $auth, true);
        });
    }

    /**
     * Modification d'un brouillon par son prescripteur (notes et/ou lignes, remplacées en bloc).
     */
    public function update(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'prescriptions.write');
        $data = $this->validator->validate($input, [
            'notes' => 'nullable|string|max:2000',
            'items' => 'nullable|array',
            'version' => 'nullable|integer|min:1',
        ]);
        $rows = array_key_exists('items', $data) ? $this->items->validate((array) $data['items']) : null;
        if ($rows === []) {
            throw new ValidationException(['items' => ['Ajoutez au moins un médicament.']]);
        }

        return $this->db->transaction(function () use ($uuid, $data, $rows, $auth, $request): array {
            $prescription = $this->lock($uuid);
            $this->assertPrescriber($auth, $prescription);
            if ($prescription['status'] !== 'BROUILLON') {
                throw HttpException::conflict('Une ordonnance signée ou annulée n\'est plus modifiable.', 'INVALID_TRANSITION');
            }
            if (isset($data['version']) && (int) $data['version'] !== (int) $prescription['version']) {
                throw HttpException::conflict('L\'ordonnance a été modifiée entre-temps.', 'VERSION_CONFLICT');
            }
            $now = Clock::nowForDatabase();
            $changes = ['updated_by' => $auth->userId(), 'updated_at' => $now];
            $fields = [];
            if (array_key_exists('notes', $data)) {
                $changes['notes'] = $data['notes'];
                $fields[] = 'notes';
            }
            if ($rows !== null) {
                $this->db->execute(
                    'UPDATE prescription_items SET deleted_at = ?, updated_at = ?, version = version + 1 WHERE prescription_id = ? AND deleted_at IS NULL',
                    [$now, $now, (int) $prescription['id']]
                );
                $this->insertItems((int) $prescription['id'], $rows, $auth);
                $fields[] = 'items';
            }
            $this->db->update('prescriptions', $changes, 'id = ?', [(int) $prescription['id']]);
            $this->db->execute('UPDATE prescriptions SET version = version + 1 WHERE id = ?', [(int) $prescription['id']]);
            $this->journal->record('prescription', $uuid, ChangeJournal::UPSERT, (int) $prescription['patient_id']);
            // Le journal d'audit garde le nom des champs modifiés, jamais leur contenu.
            $this->audit->record('PRESCRIPTION_UPDATED', $request, 'prescription', $uuid, null, ['fields' => $fields]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescriptions WHERE id = ?', [(int) $prescription['id']]), $auth, true);
        });
    }

    public function sign(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'prescriptions.sign');
        $prescription = $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $prescription = $this->lock($uuid);
            $this->assertPrescriber($auth, $prescription);
            if ($prescription['status'] !== 'BROUILLON') {
                throw HttpException::conflict('Seul un brouillon peut être signé.', 'INVALID_TRANSITION');
            }
            $now = Clock::nowForDatabase();
            $this->db->update('prescriptions', [
                'status' => 'SIGNEE',
                'signed_at' => $now,
                'updated_by' => $auth->userId(),
                'updated_at' => $now,
            ], 'id = ?', [(int) $prescription['id']]);
            $this->db->execute('UPDATE prescriptions SET version = version + 1 WHERE id = ?', [(int) $prescription['id']]);
            $this->journal->record('prescription', $uuid, ChangeJournal::UPSERT, (int) $prescription['patient_id']);
            $this->audit->record('PRESCRIPTION_SIGNED', $request, 'prescription', $uuid, ['status' => 'BROUILLON'], ['status' => 'SIGNEE']);
            return $prescription;
        });

        $patient = $this->patients->findById((int) $prescription['patient_id']);
        if ($patient !== null && $patient['user_id'] !== null) {
            $this->notifications->notify(
                (int) $patient['user_id'],
                'PRESCRIPTION_SIGNED',
                'Nouvelle ordonnance',
                'Une ordonnance est disponible dans votre dossier.',
                'prescription',
                $uuid
            );
        }
        return $this->present((array) $this->db->fetchOne('SELECT * FROM prescriptions WHERE id = ?', [(int) $prescription['id']]), $auth, true);
    }

    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'prescriptions.write');
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $prescription = $this->lock($uuid);
            $this->assertPrescriber($auth, $prescription);
            if ($prescription['status'] === 'ANNULEE') {
                throw HttpException::conflict('Cette ordonnance est déjà annulée.', 'INVALID_TRANSITION');
            }
            $this->db->update('prescriptions', [
                'status' => 'ANNULEE',
                'cancel_reason' => $data['reason'],
                'updated_by' => $auth->userId(),
                'updated_at' => Clock::nowForDatabase(),
            ], 'id = ?', [(int) $prescription['id']]);
            $this->db->execute('UPDATE prescriptions SET version = version + 1 WHERE id = ?', [(int) $prescription['id']]);
            $this->journal->record('prescription', $uuid, ChangeJournal::UPSERT, (int) $prescription['patient_id']);
            $this->audit->record('PRESCRIPTION_CANCELLED', $request, 'prescription', $uuid, ['status' => $prescription['status']], ['status' => 'ANNULEE']);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM prescriptions WHERE id = ?', [(int) $prescription['id']]), $auth, true);
        });
    }

    public function findOrFail(string $uuid): array
    {
        $prescription = $this->db->fetchOne('SELECT * FROM prescriptions WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($prescription === null) {
            throw HttpException::notFound('Ordonnance introuvable.');
        }
        return $prescription;
    }

    /**
     * @param bool $detailed Inclure les lignes et les alertes (fiche détaillée)
     */
    public function present(array $row, AuthContext $auth, bool $detailed): array
    {
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $prescriber = $this->db->fetchOne('SELECT uuid, first_name, last_name FROM users WHERE id = ?', [(int) $row['prescriber_id']]);
        $template = $row['template_id'] !== null
            ? $this->db->fetchOne('SELECT uuid, name FROM prescription_templates WHERE id = ?', [(int) $row['template_id']])
            : null;
        $consultationUuid = $row['consultation_id'] !== null
            ? $this->db->fetchValue('SELECT uuid FROM consultations WHERE id = ?', [(int) $row['consultation_id']])
            : null;
        $item = [
            'id' => (string) $row['uuid'],
            'patient_id' => (string) $patient['uuid'],
            'patient' => [
                'id' => (string) $patient['uuid'],
                'file_number' => $patient['file_number'],
                'name' => $patient['first_name'] . ' ' . $patient['last_name'],
            ],
            'consultation_id' => $consultationUuid !== null ? (string) $consultationUuid : null,
            'prescriber' => $prescriber !== null
                ? ['id' => (string) $prescriber['uuid'], 'name' => $prescriber['first_name'] . ' ' . $prescriber['last_name']]
                : null,
            'template' => $template !== null
                ? ['id' => (string) $template['uuid'], 'name' => (string) $template['name'], 'template_version' => (int) $row['template_version']]
                : null,
            'status' => (string) $row['status'],
            'signed_at' => Clock::toIso($row['signed_at']),
            'cancel_reason' => $row['cancel_reason'],
            'version' => (int) $row['version'],
            'created_at' => Clock::toIso((string) $row['created_at']),
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if ($auth->isPatient()) {
            if ($detailed && $row['status'] === 'SIGNEE' && $this->policy->owns($auth, $patient)) {
                $item['items'] = $this->items->present($this->itemRows((int) $row['id']));
                $item['notes'] = $row['notes'];
            }
            return $item;
        }
        if ($this->policy->canReadMedical($auth, $patient)) {
            $item['notes'] = $row['notes'];
            if ($detailed) {
                $rows = $this->itemRows((int) $row['id']);
                $item['items'] = $this->items->present($rows);
                $item['alerts'] = $row['status'] === 'BROUILLON' ? $this->items->allergyAlerts((int) $row['patient_id'], $rows) : [];
            }
        }
        return $item;
    }

    private function itemRows(int $prescriptionId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM prescription_items WHERE prescription_id = ? AND deleted_at IS NULL ORDER BY sort_order, id',
            [$prescriptionId]
        );
    }

    private function insertItems(int $prescriptionId, array $rows, AuthContext $auth): void
    {
        $now = Clock::nowForDatabase();
        foreach (array_values($rows) as $index => $row) {
            $this->db->insert('prescription_items', ['sort_order' => $index] + $row + [
                'uuid' => Uuid::v4(),
                'prescription_id' => $prescriptionId,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function assertPrescriber(AuthContext $auth, array $prescription): void
    {
        if ((int) $prescription['prescriber_id'] !== $auth->userId()) {
            throw HttpException::forbidden('Seul le prescripteur peut modifier, signer ou annuler cette ordonnance.');
        }
    }

    private function lock(string $uuid): array
    {
        $prescription = $this->db->fetchOne('SELECT * FROM prescriptions WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($prescription === null) {
            throw HttpException::notFound('Ordonnance introuvable.');
        }
        return $prescription;
    }

    private static function require(AuthContext $auth, string $permission): void
    {
        if (!$auth->can($permission)) {
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
