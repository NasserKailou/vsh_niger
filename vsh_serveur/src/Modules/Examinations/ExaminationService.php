<?php

declare(strict_types=1);

namespace Vsh\Modules\Examinations;

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
 * Examens (rapport C7) :
 *   PRESCRIT ──start──▶ EN_COURS ──results──▶ (résultats) ──complete──▶ TERMINE ──validate──▶ VALIDE
 *   PRESCRIT / EN_COURS ──cancel (motif)──▶ ANNULE
 *
 * - Les résultats sont structurés selon les paramètres configurés du type d'examen ; l'indicateur
 *   « hors valeurs de référence » applique les valeurs saisies par la clinique (aucune norme codée).
 * - Validation (D-005) : médecin traitant, prescripteur ou membre de l'équipe en charge, jamais le
 *   technicien qui a saisi les résultats. Le patient ne voit que les résultats VALIDÉS.
 * - Le technicien voit ce qui est utile à l'examen (identité minimale, renseignements cliniques,
 *   résultats) sans accéder au reste du dossier médical.
 */
final class ExaminationService
{
    private const ACTIVE_STATUSES = ['PRESCRIT', 'EN_COURS'];

    /** @var Database */
    private $db;

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
        PatientRepository $patients,
        PatientPolicy $policy,
        ConsultationService $consultations,
        NotificationService $notifications,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->consultations = $consultations;
        $this->notifications = $notifications;
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
            'status' => 'nullable|in:PRESCRIT,EN_COURS,TERMINE,VALIDE,ANNULE',
            'queue' => 'nullable|boolean',
            'mine' => 'nullable|boolean',
        ]);
        $where = ['e.deleted_at IS NULL'];
        $params = [];
        if (isset($data['patient_id'])) {
            $where[] = 'p.uuid = ?';
            $params[] = $data['patient_id'];
        }
        if (isset($data['status'])) {
            $where[] = 'e.status = ?';
            $params[] = $data['status'];
        }
        if (!empty($data['queue'])) {
            $where[] = "e.status IN ('PRESCRIT', 'EN_COURS')";
        }
        if (!empty($data['mine'])) {
            $where[] = '(e.prescribed_by = ? OR e.technician_id = ?)';
            array_push($params, $auth->userId(), $auth->userId());
        }
        $sql = ' FROM examinations e JOIN patients p ON p.id = e.patient_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            "SELECT e.*" . $sql . " ORDER BY e.priority = 'URGENTE' DESC, e.prescribed_at DESC, e.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row) use ($auth): array {
            return $this->present($row, $auth, false);
        }, $rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $examination = $this->findOrFail($uuid);
        $item = $this->present($examination, $auth, true);
        if (isset($item['results']) && !$auth->isPatient()) {
            $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'examination', $uuid);
        }
        return $item;
    }

    /**
     * Examens VALIDÉS d'un dossier rattaché au compte patient.
     */
    public function forOwnPatient(string $patientUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM examinations WHERE patient_id = ? AND status = 'VALIDE' AND deleted_at IS NULL ORDER BY validated_at DESC",
            [(int) $patient['id']]
        );
        return array_map(function (array $row) use ($auth): array {
            return $this->present($row, $auth, true);
        }, $rows);
    }

    public function prescribe(array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        self::require($auth, 'examinations.prescribe');
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'consultation_id' => 'nullable|uuid',
            'examination_type_id' => 'required|uuid',
            'priority' => 'nullable|in:NORMALE,URGENTE',
            'clinical_info' => 'nullable|string|max:2000',
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
        $type = $this->db->fetchOne('SELECT id, code, active FROM examination_types WHERE uuid = ? AND deleted_at IS NULL', [$data['examination_type_id']]);
        if ($type === null || !(bool) $type['active']) {
            throw new ValidationException(['examination_type_id' => ['Type d\'examen introuvable ou désactivé.']]);
        }
        if ($uuid !== null && $this->db->fetchValue('SELECT id FROM examinations WHERE uuid = ?', [$uuid]) !== null) {
            throw HttpException::conflict('Cet examen existe déjà.', 'ALREADY_EXISTS');
        }

        return $this->db->transaction(function () use ($uuid, $patient, $consultationId, $type, $data, $auth, $request): array {
            $uuid = $uuid ?? Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('examinations', [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'consultation_id' => $consultationId,
                'examination_type_id' => (int) $type['id'],
                'priority' => $data['priority'] ?? 'NORMALE',
                'clinical_info' => $data['clinical_info'] ?? null,
                'prescribed_by' => $auth->userId(),
                'prescribed_at' => $now,
                'status' => 'PRESCRIT',
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->journal->record('examination', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record('EXAMINATION_PRESCRIBED', $request, 'examination', $uuid, null, ['type' => $type['code']]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM examinations WHERE id = ?', [$id]), $auth, true);
        });
    }

    public function start(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'examinations.perform');
        return $this->transition($uuid, ['PRESCRIT'], [
            'status' => 'EN_COURS',
            'technician_id' => $auth->userId(),
            'performed_at' => Clock::nowForDatabase(),
        ], 'EXAMINATION_STARTED', $auth, $request);
    }

    /**
     * Enregistre (ou remplace, tant que l'examen n'est pas terminé) les résultats.
     */
    public function recordResults(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'examinations.perform');
        $data = $this->validator->validate($input, [
            'results' => 'required|array|min:1|max:100',
            'comment' => 'nullable|string|max:2000',
        ]);

        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $examination = $this->lock($uuid);
            if (!in_array($examination['status'], self::ACTIVE_STATUSES, true)) {
                throw HttpException::conflict('Les résultats ne sont plus modifiables à ce stade.', 'INVALID_TRANSITION');
            }
            $patient = (array) $this->patients->findById((int) $examination['patient_id']);
            $rows = $this->buildResultRows($data['results'], (int) $examination['examination_type_id']);
            $now = Clock::nowForDatabase();
            $this->db->execute(
                'UPDATE examination_results SET deleted_at = ?, updated_at = ?, version = version + 1 WHERE examination_id = ? AND deleted_at IS NULL',
                [$now, $now, (int) $examination['id']]
            );
            foreach ($rows as $row) {
                $this->db->insert('examination_results', $row + [
                    'uuid' => Uuid::v4(),
                    'examination_id' => (int) $examination['id'],
                    'recorded_by' => $auth->userId(),
                    'recorded_at' => $now,
                    'created_by' => $auth->userId(),
                    'updated_by' => $auth->userId(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $changes = ['comment' => $data['comment'] ?? $examination['comment'], 'updated_by' => $auth->userId(), 'updated_at' => $now];
            if ($examination['status'] === 'PRESCRIT') {
                $changes += ['status' => 'EN_COURS', 'technician_id' => $auth->userId(), 'performed_at' => $now];
            }
            $this->db->update('examinations', $changes, 'id = ?', [(int) $examination['id']]);
            $this->db->execute('UPDATE examinations SET version = version + 1 WHERE id = ?', [(int) $examination['id']]);
            $this->journal->record('examination', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record('EXAMINATION_RESULTS_RECORDED', $request, 'examination', $uuid, null, ['results' => count($rows)]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM examinations WHERE id = ?', [(int) $examination['id']]), $auth, true);
        });
    }

    public function complete(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'examinations.perform');
        $examination = $this->findOrFail($uuid);
        $count = (int) $this->db->fetchValue('SELECT COUNT(*) FROM examination_results WHERE examination_id = ? AND deleted_at IS NULL', [(int) $examination['id']]);
        if ($count === 0) {
            throw new ValidationException(['results' => ['Saisissez les résultats avant de terminer l\'examen.']]);
        }
        return $this->transition($uuid, ['EN_COURS'], ['status' => 'TERMINE', 'completed_at' => Clock::nowForDatabase()], 'EXAMINATION_COMPLETED', $auth, $request);
    }

    /**
     * Validation médicale (D-005). Le patient est alors notifié, sans aucun détail médical.
     */
    public function validate(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'examinations.validate');
        $examination = $this->findOrFail($uuid);
        if ($examination['technician_id'] !== null && (int) $examination['technician_id'] === $auth->userId()) {
            throw HttpException::forbidden('Vous ne pouvez pas valider des résultats que vous avez vous-même saisis.', 'SELF_VALIDATION');
        }
        if (!$this->canValidate($auth, $examination)) {
            throw HttpException::forbidden('Seuls le médecin traitant, le prescripteur ou l\'équipe en charge peuvent valider ces résultats.');
        }
        $result = $this->transition($uuid, ['TERMINE'], [
            'status' => 'VALIDE',
            'validated_by' => $auth->userId(),
            'validated_at' => Clock::nowForDatabase(),
        ], 'EXAMINATION_VALIDATED', $auth, $request);

        $patient = $this->patients->findById((int) $examination['patient_id']);
        if ($patient !== null && $patient['user_id'] !== null) {
            $this->notifications->notify(
                (int) $patient['user_id'],
                'EXAM_RESULT',
                'Résultat disponible',
                'Un résultat d\'examen est disponible dans votre dossier.',
                'examination',
                $uuid
            );
        }
        return $result;
    }

    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        if (!$auth->can('examinations.prescribe') && !$auth->can('examinations.perform')) {
            throw HttpException::forbidden();
        }
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
        return $this->transition($uuid, self::ACTIVE_STATUSES, ['status' => 'ANNULE', 'cancel_reason' => $data['reason']], 'EXAMINATION_CANCELLED', $auth, $request);
    }

    /**
     * D-005 : médecin traitant du patient, prescripteur, ou membre (à la date du jour) de l'équipe
     * affectée à la visite à domicile d'où provient l'examen.
     */
    public function canValidate(AuthContext $auth, array $examination): bool
    {
        if ((int) $examination['prescribed_by'] === $auth->userId()) {
            return true;
        }
        $patient = $this->patients->findById((int) $examination['patient_id']);
        if ($patient !== null && $patient['attending_physician_id'] !== null && (int) $patient['attending_physician_id'] === $auth->userId()) {
            return true;
        }
        if ($examination['consultation_id'] === null) {
            return false;
        }
        $today = Clock::now()->format('Y-m-d');
        return $this->db->fetchValue(
            'SELECT 1 FROM consultations c
             JOIN homecare_interventions hi ON hi.request_id = c.homecare_request_id AND hi.released_at IS NULL
             JOIN team_members tm ON tm.team_id = hi.team_id AND tm.user_id = ? AND tm.from_date <= ? AND (tm.to_date IS NULL OR tm.to_date >= ?)
             WHERE c.id = ? LIMIT 1',
            [$auth->userId(), $today, $today, (int) $examination['consultation_id']]
        ) !== null;
    }

    public function findOrFail(string $uuid): array
    {
        $examination = $this->db->fetchOne('SELECT * FROM examinations WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($examination === null) {
            throw HttpException::notFound('Examen introuvable.');
        }
        return $examination;
    }

    /**
     * @param bool $withResults Inclure les résultats (fiche détaillée)
     */
    public function present(array $row, AuthContext $auth, bool $withResults): array
    {
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $type = (array) $this->db->fetchOne('SELECT uuid, code, label, category, sample_type FROM examination_types WHERE id = ?', [(int) $row['examination_type_id']]);
        $people = $this->people([(int) $row['prescribed_by'], (int) $row['technician_id'], (int) $row['validated_by']]);
        $consultationUuid = $row['consultation_id'] !== null
            ? $this->db->fetchValue('SELECT uuid FROM consultations WHERE id = ?', [(int) $row['consultation_id']])
            : null;
        $item = [
            'id' => (string) $row['uuid'],
            'patient' => [
                'id' => (string) $patient['uuid'],
                'file_number' => $patient['file_number'],
                'name' => $patient['first_name'] . ' ' . $patient['last_name'],
                'sex' => (string) $patient['sex'],
                'birth_date' => $patient['birth_date'],
            ],
            'consultation_id' => $consultationUuid !== null ? (string) $consultationUuid : null,
            'examination_type' => ['id' => (string) $type['uuid'], 'code' => (string) $type['code'], 'label' => (string) $type['label'], 'sample_type' => $type['sample_type']],
            'priority' => (string) $row['priority'],
            'status' => (string) $row['status'],
            'prescribed_by' => $people[(int) $row['prescribed_by']] ?? null,
            'prescribed_at' => Clock::toIso((string) $row['prescribed_at']),
            'technician' => $row['technician_id'] !== null ? ($people[(int) $row['technician_id']] ?? null) : null,
            'performed_at' => Clock::toIso($row['performed_at']),
            'completed_at' => Clock::toIso($row['completed_at']),
            'validated_by' => $row['validated_by'] !== null ? ($people[(int) $row['validated_by']] ?? null) : null,
            'validated_at' => Clock::toIso($row['validated_at']),
            'cancel_reason' => $row['cancel_reason'],
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if ($auth->isPatient()) {
            // Le patient ne voit les résultats qu'une fois validés.
            if ($withResults && $row['status'] === 'VALIDE' && $this->policy->owns($auth, $patient)) {
                $item['results'] = $this->results((int) $row['id']);
                $item['comment'] = $row['comment'];
            }
            return $item;
        }
        if ($this->policy->canReadMedical($auth, $patient) || $auth->can('examinations.perform')) {
            $item['clinical_info'] = $row['clinical_info'];
            if ($withResults) {
                $item['results'] = $this->results((int) $row['id']);
                $item['comment'] = $row['comment'];
            }
        }
        return $item;
    }

    private function results(int $examinationId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT r.*, p.uuid AS parameter_uuid, p.code AS parameter_code
             FROM examination_results r LEFT JOIN examination_type_parameters p ON p.id = r.parameter_id
             WHERE r.examination_id = ? AND r.deleted_at IS NULL ORDER BY p.sort_order IS NULL, p.sort_order, r.id',
            [$examinationId]
        );
        return array_map(function (array $row): array {
            return [
                'id' => (string) $row['uuid'],
                'parameter_id' => $row['parameter_uuid'],
                'parameter_code' => $row['parameter_code'],
                'label' => (string) $row['label'],
                'value_numeric' => $row['value_numeric'] !== null ? (float) $row['value_numeric'] : null,
                'value_text' => $row['value_text'],
                'unit' => $row['unit'],
                'reference_text' => $row['reference_text'],
                'is_abnormal' => $row['is_abnormal'] === null ? null : (bool) $row['is_abnormal'],
            ];
        }, $rows);
    }

    /**
     * @return array[] Lignes prêtes à insérer
     */
    private function buildResultRows(array $items, int $typeId): array
    {
        $parameters = [];
        foreach ($this->db->fetchAll('SELECT * FROM examination_type_parameters WHERE examination_type_id = ?', [$typeId]) as $parameter) {
            $parameters[(string) $parameter['uuid']] = $parameter;
        }
        $rows = [];
        $errors = [];
        foreach (array_values($items) as $index => $item) {
            $prefix = 'results.' . $index . '.';
            try {
                $data = $this->validator->validate(is_array($item) ? $item : [], [
                    'parameter_id' => 'nullable|uuid',
                    'label' => 'nullable|string|max:190',
                    'value_numeric' => 'nullable|numeric',
                    'value_text' => 'nullable|string|max:2000',
                    'unit' => 'nullable|string|max:30',
                ]);
            } catch (ValidationException $exception) {
                foreach ($exception->getErrors() as $field => $messages) {
                    $errors[$prefix . $field] = $messages;
                }
                continue;
            }
            $parameter = isset($data['parameter_id']) ? ($parameters[$data['parameter_id']] ?? null) : null;
            if (isset($data['parameter_id']) && $parameter === null) {
                $errors[$prefix . 'parameter_id'] = ['Paramètre inconnu pour ce type d\'examen.'];
                continue;
            }
            $numeric = $data['value_numeric'] ?? null;
            $text = $data['value_text'] ?? null;
            if ($parameter !== null) {
                if ($parameter['value_type'] === 'NUMERIC' && $numeric === null) {
                    $errors[$prefix . 'value_numeric'] = ['Valeur numérique attendue.'];
                    continue;
                }
                if ($parameter['value_type'] === 'CHOICE') {
                    $choices = (array) json_decode((string) $parameter['choices'], true);
                    if (!in_array($text, $choices, true)) {
                        $errors[$prefix . 'value_text'] = ['Valeur possible : ' . implode(', ', $choices) . '.'];
                        continue;
                    }
                }
            } elseif (!isset($data['label'])) {
                $errors[$prefix . 'label'] = ['Libellé obligatoire pour un résultat libre.'];
                continue;
            }
            if ($numeric === null && $text === null) {
                $errors[$prefix . 'value_text'] = ['Indiquez une valeur.'];
                continue;
            }
            $abnormal = null;
            $reference = null;
            if ($parameter !== null) {
                $min = $parameter['ref_min'] !== null ? (float) $parameter['ref_min'] : null;
                $max = $parameter['ref_max'] !== null ? (float) $parameter['ref_max'] : null;
                if ($numeric !== null && ($min !== null || $max !== null)) {
                    $abnormal = ($min !== null && $numeric < $min) || ($max !== null && $numeric > $max);
                }
                $reference = $parameter['ref_text'] ?? (($min !== null || $max !== null)
                    ? trim(($min !== null ? self::number($min) : '') . ' – ' . ($max !== null ? self::number($max) : '') . ' ' . ($parameter['unit'] ?? ''))
                    : null);
            }
            $rows[] = [
                'parameter_id' => $parameter !== null ? (int) $parameter['id'] : null,
                'label' => $parameter !== null ? (string) $parameter['label'] : (string) $data['label'],
                'value_numeric' => $numeric,
                'value_text' => $text,
                'unit' => $data['unit'] ?? ($parameter['unit'] ?? null),
                'reference_text' => $reference,
                'is_abnormal' => $abnormal,
            ];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $rows;
    }

    private function transition(string $uuid, array $from, array $changes, string $action, AuthContext $auth, Request $request): array
    {
        return $this->db->transaction(function () use ($uuid, $from, $changes, $action, $auth, $request): array {
            $examination = $this->lock($uuid);
            if (!in_array($examination['status'], $from, true)) {
                throw HttpException::conflict(
                    sprintf('Action impossible : l\'examen est à l\'état %s.', $examination['status']),
                    'INVALID_TRANSITION'
                );
            }
            $this->db->update('examinations', $changes + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $examination['id']]);
            $this->db->execute('UPDATE examinations SET version = version + 1 WHERE id = ?', [(int) $examination['id']]);
            $this->journal->record('examination', $uuid, ChangeJournal::UPSERT, (int) $examination['patient_id']);
            $this->audit->record($action, $request, 'examination', $uuid, ['status' => $examination['status']], ['status' => $changes['status']]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM examinations WHERE id = ?', [(int) $examination['id']]), $auth, true);
        });
    }

    private function lock(string $uuid): array
    {
        $examination = $this->db->fetchOne('SELECT * FROM examinations WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($examination === null) {
            throw HttpException::notFound('Examen introuvable.');
        }
        return $examination;
    }

    /**
     * @return array<int,array{id: string, name: string}>
     */
    private function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->fetchAll(
            'SELECT id, uuid, first_name, last_name FROM users WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) as $row) {
            $map[(int) $row['id']] = ['id' => (string) $row['uuid'], 'name' => $row['first_name'] . ' ' . $row['last_name']];
        }
        return $map;
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, ',', ''), '0'), ',');
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
