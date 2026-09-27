<?php

declare(strict_types=1);

namespace Vsh\Modules\Homecare;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Geo;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Consultations\ConsultationService;
use Vsh\Modules\Notifications\NotificationService;
use Vsh\Modules\Patients\PatientChildService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Settings\SettingsService;
use Vsh\Modules\Sync\PatientSnapshot;
use Vsh\Modules\Teams\TeamService;

/**
 * Visites à domicile (rapport C5, D-003). La machine à états est définie ICI, côté serveur :
 *
 *   NOUVELLE ──approve / auto──▶ EN_ATTENTE ──accept (équipe) / assign (régulation)──▶ PRISE_EN_CHARGE
 *   PRISE_EN_CHARGE ──depart──▶ EN_ROUTE ──arrive──▶ SUR_PLACE ──start──▶ EN_COURS ──complete──▶ TERMINEE
 *   PRISE_EN_CHARGE ──release (désistement, motif)──▶ EN_ATTENTE
 *   Sorties : ANNULEE_PATIENT (avant SUR_PLACE), ANNULEE_CLINIQUE (motif), ECHEC (motif).
 *   TERMINEE ──▶ FACTUREE : posé par la facturation (étape 8).
 *
 * - Acceptation concurrente : mise à jour conditionnelle, la première équipe gagne (D-003).
 * - Chaque transition est historisée : de, vers, qui, quand (heure de l'appareil), où, appareil.
 * - Les actions de terrain sont réservées aux membres ACTUELS de l'équipe affectée.
 */
final class HomecareService
{
    public const OPEN_STATUSES = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];
    public const ACTIVE_STATUSES = ['PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];

    /** Actions de terrain : états de départ admis → état d'arrivée. */
    private const FIELD_ACTIONS = [
        'depart' => [['PRISE_EN_CHARGE'], 'EN_ROUTE'],
        'arrive' => [['EN_ROUTE'], 'SUR_PLACE'],
        'start' => [['SUR_PLACE'], 'EN_COURS'],
        'complete' => [['EN_COURS'], 'TERMINEE'],
        'fail' => [['PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'], 'ECHEC'],
    ];

    private const MAX_TRACE_POINTS = 100;
    private const MAX_TRACK_READ = 5000;

    /** @var Database */
    private $db;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var TeamService */
    private $teams;

    /** @var ConsultationService */
    private $consultations;

    /** @var NotificationService */
    private $notifications;

    /** @var SettingsService */
    private $settings;

    /** @var PatientSnapshot */
    private $snapshot;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    /** @var PatientChildService */
    private $patientChildren;

    public function __construct(
        Database $db,
        PatientRepository $patients,
        PatientPolicy $policy,
        TeamService $teams,
        ConsultationService $consultations,
        NotificationService $notifications,
        SettingsService $settings,
        PatientSnapshot $snapshot,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator,
        PatientChildService $patientChildren
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->teams = $teams;
        $this->consultations = $consultations;
        $this->notifications = $notifications;
        $this->settings = $settings;
        $this->snapshot = $snapshot;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->patientChildren = $patientChildren;
    }

    // ------------------------------------------------------------------ Lecture

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($query, [
            'status' => ['nullable', 'string', 'regex:/^[A-Z_]+(,[A-Z_]+)*$/'],
            'open' => 'nullable|boolean',
            'queue' => 'nullable|boolean',
            'mine' => 'nullable|boolean',
            'patient_id' => 'nullable|uuid',
            'team_id' => 'nullable|uuid',
        ]);
        list($teamSql, $teamParams) = TeamService::teamIdsSql($auth->userId());
        $where = ['r.deleted_at IS NULL'];
        $params = [];
        if (!$auth->can('homecare.dispatch')) {
            // Hors régulation : ses demandes, celles de ses équipes et, si l'auto-attribution est permise, la file d'attente.
            $visible = ['r.requested_by = ?', 'r.assigned_team_id IN (' . $teamSql . ')'];
            $params = array_merge([$auth->userId()], $teamParams);
            if ($auth->can('homecare.intervene') && $this->selfAssignAllowed()) {
                $visible[] = "r.status = 'EN_ATTENTE'";
            }
            $where[] = '(' . implode(' OR ', $visible) . ')';
        }
        if (isset($data['status'])) {
            $statuses = explode(',', $data['status']);
            $where[] = 'r.status IN (' . implode(', ', array_fill(0, count($statuses), '?')) . ')';
            $params = array_merge($params, $statuses);
        }
        if (!empty($data['open'])) {
            $where[] = "r.status IN ('" . implode("', '", self::OPEN_STATUSES) . "')";
        }
        if (!empty($data['queue'])) {
            $where[] = "r.status = 'EN_ATTENTE'";
        }
        if (!empty($data['mine'])) {
            $where[] = 'r.assigned_team_id IN (' . $teamSql . ')';
            $params = array_merge($params, $teamParams);
        }
        if (isset($data['patient_id'])) {
            $where[] = 'p.uuid = ?';
            $params[] = $data['patient_id'];
        }
        if (isset($data['team_id'])) {
            $where[] = 't.uuid = ?';
            $params[] = $data['team_id'];
        }
        $sql = ' FROM homecare_requests r JOIN patients p ON p.id = r.patient_id LEFT JOIN teams t ON t.id = r.assigned_team_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            "SELECT r.*" . $sql . " ORDER BY r.urgency = 'URGENTE' DESC, r.created_at, r.id LIMIT ? OFFSET ?",
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row) use ($auth): array {
            return $this->present($row, (string) $this->access($auth, $row), false);
        }, $rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $row = $this->findOrFail($uuid);
        $level = $this->access($auth, $row);
        if ($level === null || $level === 'minimal') {
            throw HttpException::notFound('Demande introuvable.');
        }
        return $this->present($row, $level, true);
    }

    public function forOwnPatient(string $patientUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM homecare_requests WHERE patient_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 50',
            [(int) $patient['id']]
        );
        return array_map(function (array $row): array {
            return $this->present($row, 'patient', false);
        }, $rows);
    }

    /**
     * Carte des visites en cours : position des demandes et dernière position connue des équipes.
     * Aucune donnée médicale (ni motif, ni nom du patient) : numéro de dossier seulement.
     */
    public function map(Request $request): array
    {
        $auth = self::auth($request);
        if (!$auth->can('map.read') && !$auth->can('homecare.dispatch')) {
            throw HttpException::forbidden();
        }
        $rows = $this->db->fetchAll(
            "SELECT r.*, p.file_number, t.uuid AS team_uuid, t.label AS team_label
             FROM homecare_requests r JOIN patients p ON p.id = r.patient_id LEFT JOIN teams t ON t.id = r.assigned_team_id
             WHERE r.deleted_at IS NULL AND r.status IN ('" . implode("', '", self::OPEN_STATUSES) . "')
             ORDER BY r.urgency = 'URGENTE' DESC, r.created_at"
        );
        $settings = $this->geoSettings();
        return array_map(function (array $row) use ($settings): array {
            $position = null;
            $home = $row['latitude'] !== null ? [(float) $row['latitude'], (float) $row['longitude']] : null;
            $intervention = $this->activeIntervention((int) $row['id']);
            if ($intervention !== null && in_array($row['status'], ['EN_ROUTE', 'SUR_PLACE', 'EN_COURS'], true)) {
                $last = $this->db->fetchOne(
                    'SELECT latitude, longitude, accuracy_m, captured_at FROM homecare_locations WHERE intervention_id = ? ORDER BY captured_at DESC, id DESC LIMIT 1',
                    [(int) $intervention['id']]
                );
                if ($last !== null) {
                    $position = $this->positionView($last, $home, $settings);
                }
            }
            return [
                'id' => (string) $row['uuid'],
                'file_number' => $row['file_number'],
                'status' => (string) $row['status'],
                'urgency' => (string) $row['urgency'],
                'latitude' => $row['latitude'] !== null ? (float) $row['latitude'] : null,
                'longitude' => $row['longitude'] !== null ? (float) $row['longitude'] : null,
                'accuracy_m' => $row['gps_accuracy_m'] !== null ? (float) $row['gps_accuracy_m'] : null,
                'imprecise' => $row['gps_accuracy_m'] !== null && (float) $row['gps_accuracy_m'] > $settings['low_accuracy_m'],
                'address_text' => $row['address_text'],
                'landmark' => $row['landmark'],
                'team' => $row['team_uuid'] !== null ? ['id' => (string) $row['team_uuid'], 'label' => (string) $row['team_label']] : null,
                'team_position' => $position,
                'created_at' => Clock::toIso((string) $row['created_at']),
            ];
        }, $rows);
    }

    // ------------------------------------------------------------------ Création

    public function create(array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'reason' => 'required|string|max:2000',
            'urgency' => 'nullable|in:NORMALE,URGENTE',
            'preferred_time' => 'nullable|datetime',
            'latitude' => 'nullable|latitude',
            'longitude' => 'nullable|longitude',
            'gps_accuracy_m' => 'nullable|numeric|min:0|max:100000',
            'gps_captured_at' => 'nullable|datetime',
            'address_text' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|phone',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        if ($auth->isPatient()) {
            if (!$auth->can('homecare.request_self') || !$this->policy->owns($auth, $patient)) {
                throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
            }
        } elseif (!$auth->can('homecare.request')) {
            throw HttpException::forbidden();
        }
        if ($patient['status'] !== 'ACTIVE') {
            throw HttpException::conflict('Le dossier du patient doit être validé.', 'PATIENT_NOT_ACTIVE');
        }
        self::assertCoordinates($data);
        if (!isset($data['latitude']) && !isset($data['address_text']) && !isset($data['landmark'])) {
            throw new ValidationException(['latitude' => ['Indiquez la position GPS, une adresse ou un repère.']]);
        }
        $phone = $data['contact_phone'] ?? ($patient['phone'] ?? null);
        if ($phone === null) {
            throw new ValidationException(['contact_phone' => ['Indiquez un numéro de téléphone à joindre.']]);
        }
        if ($uuid !== null && $this->db->fetchValue('SELECT id FROM homecare_requests WHERE uuid = ?', [$uuid]) !== null) {
            throw HttpException::conflict('Cette demande existe déjà.', 'ALREADY_EXISTS');
        }
        $open = $this->db->fetchValue(
            "SELECT uuid FROM homecare_requests WHERE patient_id = ? AND deleted_at IS NULL AND status IN ('" . implode("', '", self::OPEN_STATUSES) . "') LIMIT 1",
            [(int) $patient['id']]
        );
        if ($open !== null) {
            throw HttpException::conflict('Une visite à domicile est déjà en cours pour ce patient.', 'HOMECARE_ALREADY_OPEN', ['existing_id' => [(string) $open]]);
        }
        $autoAccept = (bool) $this->settings->get('homecare.auto_accept_new_requests', true);

        return $this->db->transaction(function () use ($uuid, $patient, $data, $phone, $autoAccept, $auth, $request): array {
            $uuid = $uuid ?? Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('homecare_requests', [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'requested_by' => $auth->userId(),
                'reason' => $data['reason'],
                'urgency' => $data['urgency'] ?? 'NORMALE',
                'preferred_time' => $data['preferred_time'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'gps_accuracy_m' => $data['gps_accuracy_m'] ?? null,
                'gps_captured_at' => isset($data['latitude']) ? ($data['gps_captured_at'] ?? $now) : null,
                'address_text' => $data['address_text'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'contact_phone' => $phone,
                'status' => $autoAccept ? 'EN_ATTENTE' : 'NOUVELLE',
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->history($id, null, 'NOUVELLE', $auth, $now);
            if ($autoAccept) {
                $this->history($id, 'NOUVELLE', 'EN_ATTENTE', $auth, $now, null, 'Validation automatique');
            }
            $row = (array) $this->db->fetchOne('SELECT * FROM homecare_requests WHERE id = ?', [$id]);
            $this->record($row, null, $autoAccept);
            $this->audit->record('HOMECARE_REQUESTED', $request, 'homecare_request', $uuid, null, ['patient_id' => $patient['uuid'], 'urgency' => $row['urgency']]);
            return $this->present($row, (string) $this->access($auth, $row), true);
        });
    }

    // ------------------------------------------------------------------ Régulation / affectation

    /** Validation manuelle d'une nouvelle demande (si la validation automatique est désactivée). */
    public function approve(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'homecare.dispatch');
        return $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['NOUVELLE']);
            $this->setStatus($row, 'EN_ATTENTE', [], $auth, null);
            return $this->finish($row, null, true, 'HOMECARE_APPROVED', $auth, $request);
        });
    }

    /**
     * Auto-attribution par une équipe (D-003). La première équipe gagne : les suivantes reçoivent INVALID_TRANSITION.
     */
    public function accept(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'homecare.intervene');
        if (!$this->selfAssignAllowed()) {
            throw HttpException::forbidden('L\'auto-attribution est désactivée : les visites sont affectées par la régulation.', 'SELF_ASSIGN_DISABLED');
        }
        $data = $this->validator->validate($input, ['team_id' => 'nullable|uuid'] + self::fieldRules());
        self::assertCoordinates($data);
        $teamId = $this->resolveOwnTeam($auth, $data['team_id'] ?? null);

        return $this->db->transaction(function () use ($uuid, $teamId, $data, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['EN_ATTENTE']);
            $this->startIntervention($row, $teamId, $auth, $data);
            return $this->finish($row, null, true, 'HOMECARE_ACCEPTED', $auth, $request);
        });
    }

    /**
     * Affectation ou réaffectation par la régulation (D-003).
     */
    public function assign(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'homecare.dispatch');
        if ($this->dispatchMode() === 'SELF_ASSIGN') {
            throw HttpException::forbidden('L\'affectation par la régulation est désactivée.', 'DISPATCH_DISABLED');
        }
        $data = $this->validator->validate($input, [
            'team_id' => 'required|uuid',
            'comment' => 'nullable|string|max:500',
        ]);
        $team = $this->db->fetchOne('SELECT * FROM teams WHERE uuid = ? AND deleted_at IS NULL', [$data['team_id']]);
        if ($team === null || !(bool) $team['active'] || !(bool) $team['is_mobile']) {
            throw new ValidationException(['team_id' => ['Équipe mobile introuvable ou inactive.']]);
        }

        return $this->db->transaction(function () use ($uuid, $team, $data, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE']);
            $previousTeam = $row['assigned_team_id'] !== null ? (int) $row['assigned_team_id'] : null;
            if ($previousTeam === (int) $team['id']) {
                throw HttpException::conflict('Cette équipe est déjà affectée à la visite.', 'ALREADY_ASSIGNED');
            }
            if ($previousTeam !== null) {
                $this->releaseIntervention((int) $row['id'], 'Réaffectation par la régulation');
            }
            $this->startIntervention($row, (int) $team['id'], $auth, ['comment' => $data['comment'] ?? null]);
            $this->notifyTeam((int) $team['id'], (string) $row['uuid']);
            return $this->finish($row, $previousTeam, $previousTeam === null, 'HOMECARE_ASSIGNED', $auth, $request);
        });
    }

    /**
     * Désistement de l'équipe (motif obligatoire) : la demande retourne dans la file d'attente.
     */
    public function release(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500'] + self::fieldRules());
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['PRISE_EN_CHARGE']);
            if (!$auth->can('homecare.dispatch')) {
                $this->assertTeamMember($auth, $row);
            }
            $previousTeam = (int) $row['assigned_team_id'];
            $this->releaseIntervention((int) $row['id'], $data['reason']);
            $this->setStatus($row, 'EN_ATTENTE', ['assigned_team_id' => null, 'assigned_by' => null, 'assigned_at' => null], $auth, $data, $data['reason']);
            return $this->finish($row, $previousTeam, true, 'HOMECARE_RELEASED', $auth, $request);
        });
    }

    // ------------------------------------------------------------------ Terrain

    /**
     * depart, arrive, start, complete, fail : membres actuels de l'équipe affectée.
     */
    public function fieldAction(string $uuid, string $action, array $input, Request $request): array
    {
        if (!isset(self::FIELD_ACTIONS[$action])) {
            throw HttpException::notFound();
        }
        $auth = self::auth($request);
        self::require($auth, 'homecare.intervene');
        $rules = self::fieldRules();
        if ($action === 'fail') {
            $rules['reason'] = 'required|string|max:500';
        }
        if ($action === 'start') {
            $rules['consultation_id'] = 'nullable|uuid';
        }
        $data = $this->validator->validate($input, $rules);
        self::assertCoordinates($data);
        list($from, $to) = self::FIELD_ACTIONS[$action];

        return $this->db->transaction(function () use ($uuid, $action, $from, $to, $data, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, $from);
            $this->assertTeamMember($auth, $row);
            $intervention = (array) $this->activeIntervention((int) $row['id']);
            $at = $data['at'] ?? Clock::nowForDatabase();
            $stamps = ['depart' => 'departed_at', 'arrive' => 'arrived_at', 'start' => 'started_at', 'complete' => 'completed_at'];
            if (isset($stamps[$action])) {
                $this->db->update('homecare_interventions', [
                    $stamps[$action] => $at,
                    'updated_by' => $auth->userId(),
                    'updated_at' => Clock::nowForDatabase(),
                ], 'id = ?', [(int) $intervention['id']]);
                $this->db->execute('UPDATE homecare_interventions SET version = version + 1 WHERE id = ?', [(int) $intervention['id']]);
            }
            if (isset($data['latitude']) && in_array($action, ['depart', 'arrive'], true)) {
                $this->location((int) $intervention['id'], $action === 'depart' ? 'DEPART' : 'ARRIVEE', $data, $auth);
            }
            $changes = [];
            if ($action === 'fail') {
                $changes = ['failure_reason' => $data['reason'], 'closed_at' => Clock::nowForDatabase()];
            } elseif ($action === 'complete') {
                $changes = ['closed_at' => Clock::nowForDatabase()];
            }
            $this->setStatus($row, $to, $changes, $auth, $data, $data['reason'] ?? null);
            if ($action === 'start') {
                $patient = (array) $this->patients->findById((int) $row['patient_id']);
                $this->consultations->openForHomecare($patient, (int) $row['id'], $request, $data['consultation_id'] ?? null);
            }
            if ($action === 'depart') {
                $this->notifyPatient($row, 'L\'équipe de soins est en route.');
            }
            return $this->finish($row, null, false, 'HOMECARE_' . strtoupper($action), $auth, $request);
        });
    }

    /**
     * Annulation : par le patient (avant l'arrivée de l'équipe) ou par la clinique (motif obligatoire).
     */
    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        return $this->db->transaction(function () use ($uuid, $input, $auth, $request): array {
            $row = $this->lock($uuid);
            if ($auth->isPatient()) {
                $patient = (array) $this->patients->findById((int) $row['patient_id']);
                if (!$auth->can('homecare.request_self') || !$this->policy->owns($auth, $patient)) {
                    throw HttpException::notFound('Demande introuvable.');
                }
                $data = $this->validator->validate($input, ['reason' => 'nullable|string|max:500']);
                self::assertFrom($row, ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE']);
                $status = 'ANNULEE_PATIENT';
            } else {
                $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
                $ownPending = (int) $row['requested_by'] === $auth->userId() && in_array($row['status'], ['NOUVELLE', 'EN_ATTENTE'], true);
                if (!$auth->can('homecare.dispatch') && !$ownPending) {
                    throw HttpException::forbidden();
                }
                self::assertFrom($row, ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE']);
                $status = 'ANNULEE_CLINIQUE';
            }
            $team = $row['assigned_team_id'] !== null ? (int) $row['assigned_team_id'] : null;
            $reason = $data['reason'] ?? null;
            if ($team !== null) {
                $this->releaseIntervention((int) $row['id'], $reason ?? 'Annulation par le patient');
            }
            $this->setStatus($row, $status, ['cancel_reason' => $reason, 'closed_at' => Clock::nowForDatabase()], $auth, null, $reason);
            if ($status === 'ANNULEE_CLINIQUE') {
                $this->notifyPatient($row, 'Votre visite à domicile a été annulée par la clinique.');
            }
            if ($team !== null) {
                $this->notifyTeam($team, (string) $row['uuid'], 'Visite à domicile annulée', 'Une visite affectée à votre équipe a été annulée.');
            }
            return $this->finish($row, null, $row['status'] === 'EN_ATTENTE', 'HOMECARE_CANCELLED', $auth, $request);
        });
    }

    /**
     * Points de trajet enregistrés par l'équipe pendant la visite (carte de régulation).
     */
    public function track(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'homecare.intervene');
        $data = $this->validator->validate($input, ['points' => 'required|array|min:1|max:' . self::MAX_TRACE_POINTS]);
        $row = $this->findOrFail($uuid);
        self::assertFrom($row, ['EN_ROUTE', 'SUR_PLACE', 'EN_COURS']);
        $this->assertTeamMember($auth, $row);
        $points = [];
        $errors = [];
        foreach (array_values($data['points']) as $index => $point) {
            try {
                $valid = $this->validator->validate(is_array($point) ? $point : [], [
                    'latitude' => 'required|latitude',
                    'longitude' => 'required|longitude',
                    'accuracy_m' => 'nullable|numeric|min:0|max:100000',
                    'captured_at' => 'required|datetime',
                ]);
                ConsultationService::assertNotFuture($valid['captured_at'], 'captured_at');
                Geo::assertUsable($valid);
                $points[] = $valid;
            } catch (ValidationException $exception) {
                foreach ($exception->getErrors() as $field => $messages) {
                    $errors['points.' . $index . '.' . $field] = $messages;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $intervention = (array) $this->activeIntervention((int) $row['id']);
        // Idempotent : un lot renvoyé après une coupure réseau n'enregistre pas deux fois les mêmes points.
        $recorded = $this->db->transaction(function () use ($points, $intervention, $auth): int {
            $count = 0;
            foreach ($points as $point) {
                $exists = $this->db->fetchValue(
                    "SELECT id FROM homecare_locations WHERE intervention_id = ? AND kind = 'TRACE' AND captured_at = ? AND latitude = ? AND longitude = ? LIMIT 1",
                    [(int) $intervention['id'], $point['captured_at'], round((float) $point['latitude'], 7), round((float) $point['longitude'], 7)]
                );
                if ($exists === null) {
                    $this->location((int) $intervention['id'], 'TRACE', $point + ['at' => $point['captured_at']], $auth);
                    $count++;
                }
            }
            return $count;
        });
        return ['recorded' => $recorded, 'duplicates' => count($points) - $recorded];
    }

    // ------------------------------------------------------------------ Géolocalisation

    /**
     * Trajet enregistré pour la visite, par prise en charge successive (régulation et équipe affectée).
     * La distance cumulée ignore les points imprécis, qui gonfleraient artificiellement le parcours.
     */
    public function trackPoints(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $row = $this->findOrFail($uuid);
        if ($auth->isPatient() || $this->access($auth, $row) === null) {
            throw HttpException::notFound('Demande introuvable.');
        }
        $member = $row['assigned_team_id'] !== null && $this->teams->isActiveMember($auth->userId(), (int) $row['assigned_team_id']);
        if (!$auth->can('homecare.dispatch') && !$member) {
            throw HttpException::forbidden('Le trajet est réservé à la régulation et à l\'équipe affectée.');
        }
        $settings = $this->geoSettings();
        $interventions = $this->db->fetchAll(
            'SELECT i.id, i.uuid, i.released_at, t.uuid AS team_uuid, t.label AS team_label
             FROM homecare_interventions i JOIN teams t ON t.id = i.team_id WHERE i.request_id = ? ORDER BY i.id',
            [(int) $row['id']]
        );
        $segments = array_map(function (array $intervention) use ($settings): array {
            $rows = $this->db->fetchAll(
                'SELECT kind, latitude, longitude, accuracy_m, captured_at FROM homecare_locations
                 WHERE intervention_id = ? ORDER BY captured_at, id LIMIT ' . self::MAX_TRACK_READ,
                [(int) $intervention['id']]
            );
            $distance = 0.0;
            $previous = null;
            $points = [];
            foreach ($rows as $point) {
                $item = [
                    'kind' => (string) $point['kind'],
                    'latitude' => (float) $point['latitude'],
                    'longitude' => (float) $point['longitude'],
                    'accuracy_m' => $point['accuracy_m'] !== null ? (float) $point['accuracy_m'] : null,
                    'captured_at' => Clock::toIso((string) $point['captured_at']),
                ];
                $precise = $item['accuracy_m'] === null || $item['accuracy_m'] <= $settings['low_accuracy_m'];
                if ($precise) {
                    if ($previous !== null) {
                        $distance += Geo::distance($previous['latitude'], $previous['longitude'], $item['latitude'], $item['longitude']);
                    }
                    $previous = $item;
                }
                $points[] = $item;
            }
            return [
                'id' => (string) $intervention['uuid'],
                'team' => ['id' => (string) $intervention['team_uuid'], 'label' => (string) $intervention['team_label']],
                'active' => $intervention['released_at'] === null,
                'distance_m' => (int) round($distance),
                'points' => $points,
            ];
        }, $interventions);

        return [
            'home' => $row['latitude'] !== null ? ['latitude' => (float) $row['latitude'], 'longitude' => (float) $row['longitude']] : null,
            'segments' => $segments,
            'settings' => $settings,
        ];
    }

    /**
     * Position exacte du domicile, relevée sur place par l'équipe (ou corrigée par la régulation).
     * Option : reporter la position sur l'adresse principale du dossier pour les visites suivantes.
     */
    public function updateHomeLocation(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        if ($auth->isPatient()) {
            throw HttpException::forbidden();
        }
        $data = $this->validator->validate($input, [
            'latitude' => 'required|latitude',
            'longitude' => 'required|longitude',
            'accuracy_m' => 'nullable|numeric|min:0|max:100000',
            'captured_at' => 'nullable|datetime',
            'landmark' => 'nullable|string|max:255',
            'update_patient_address' => 'nullable|boolean',
        ]);
        Geo::assertUsable($data);
        if (isset($data['captured_at'])) {
            ConsultationService::assertNotFuture($data['captured_at'], 'captured_at');
        }

        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $row = $this->lock($uuid);
            if ($auth->can('homecare.dispatch')) {
                self::assertFrom($row, self::OPEN_STATUSES);
            } else {
                self::require($auth, 'homecare.intervene');
                // Relevé fiable seulement sur place : l'équipe doit être arrivée.
                self::assertFrom($row, ['SUR_PLACE', 'EN_COURS']);
                $this->assertTeamMember($auth, $row);
            }
            $now = Clock::nowForDatabase();
            $capturedAt = $data['captured_at'] ?? $now;
            $changes = [
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'gps_accuracy_m' => $data['accuracy_m'] ?? null,
                'gps_captured_at' => $capturedAt,
                'updated_by' => $auth->userId(),
                'updated_at' => $now,
            ];
            if (isset($data['landmark'])) {
                $changes['landmark'] = $data['landmark'];
            }
            $this->db->update('homecare_requests', $changes, 'id = ?', [(int) $row['id']]);
            $this->db->execute('UPDATE homecare_requests SET version = version + 1 WHERE id = ?', [(int) $row['id']]);
            $this->audit->record(
                'HOMECARE_LOCATION_UPDATED',
                $request,
                'homecare_request',
                (string) $row['uuid'],
                ['latitude' => $row['latitude'], 'longitude' => $row['longitude']],
                ['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'accuracy_m' => $data['accuracy_m'] ?? null]
            );
            if (!empty($data['update_patient_address'])) {
                $this->storePatientHomePosition($row, $data, $capturedAt, $auth, $request);
            }
            return $this->finish($row, null, false, 'HOMECARE_LOCATION_SAVED', $auth, $request);
        });
    }

    /**
     * Équipes mobiles actives, les plus proches d'abord : distance à vol d'oiseau entre la dernière position
     * récente de l'équipe (visite en cours) et le domicile. Sans position récente : classement par charge.
     */
    public function dispatchOptions(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'homecare.dispatch');
        $row = $this->findOrFail($uuid);
        $settings = $this->geoSettings();
        $home = $row['latitude'] !== null ? [(float) $row['latitude'], (float) $row['longitude']] : null;
        $today = Clock::now()->format('Y-m-d');
        $teams = $this->db->fetchAll('SELECT id, uuid, label FROM teams WHERE is_mobile = 1 AND active = 1 AND deleted_at IS NULL ORDER BY label');
        $options = array_map(function (array $team) use ($home, $settings, $today, $row): array {
            $members = (int) $this->db->fetchValue(
                'SELECT COUNT(DISTINCT user_id) FROM team_members WHERE team_id = ? AND from_date <= ? AND (to_date IS NULL OR to_date >= ?)',
                [(int) $team['id'], $today, $today]
            );
            $active = (int) $this->db->fetchValue(
                "SELECT COUNT(*) FROM homecare_requests WHERE assigned_team_id = ? AND deleted_at IS NULL AND status IN ('" . implode("', '", self::ACTIVE_STATUSES) . "')",
                [(int) $team['id']]
            );
            $last = $this->db->fetchOne(
                "SELECT l.latitude, l.longitude, l.accuracy_m, l.captured_at FROM homecare_locations l
                 JOIN homecare_interventions i ON i.id = l.intervention_id JOIN homecare_requests r ON r.id = i.request_id
                 WHERE i.team_id = ? AND i.released_at IS NULL AND r.status IN ('EN_ROUTE', 'SUR_PLACE', 'EN_COURS')
                 ORDER BY l.captured_at DESC, l.id DESC LIMIT 1",
                [(int) $team['id']]
            );
            return [
                'id' => (string) $team['uuid'],
                'label' => (string) $team['label'],
                'members' => $members,
                'active_visits' => $active,
                'current' => (int) ($row['assigned_team_id'] ?? 0) === (int) $team['id'],
                'position' => $last !== null ? $this->positionView($last, $home, $settings) : null,
            ];
        }, $teams);
        usort($options, function (array $a, array $b): int {
            $rank = function (array $option): array {
                $position = $option['position'];
                $usable = $position !== null && !$position['stale'] && $position['distance_m'] !== null;
                return [$usable ? 0 : 1, $usable ? $position['distance_m'] : 0, $option['active_visits'], $option['label']];
            };
            return $rank($a) <=> $rank($b);
        });
        return ['home_located' => $home !== null, 'teams' => $options, 'settings' => $settings];
    }

    /**
     * Purge des points de trajet anciens (visites closes ou prises en charge abandonnées).
     * Les positions de départ et d'arrivée, preuves de passage, sont conservées.
     */
    public function purgeTraces(): int
    {
        $days = (int) $this->settings->get('geo.trace_retention_days', 90);
        if ($days <= 0) {
            return 0;
        }
        $cutoff = Clock::now()->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
        return $this->db->execute(
            "DELETE l FROM homecare_locations l
             JOIN homecare_interventions i ON i.id = l.intervention_id JOIN homecare_requests r ON r.id = i.request_id
             WHERE l.kind = 'TRACE' AND l.captured_at < ?
               AND (i.released_at IS NOT NULL OR r.status NOT IN ('" . implode("', '", self::OPEN_STATUSES) . "'))",
            [$cutoff]
        );
    }

    /**
     * Seuils de géolocalisation (paramètres modifiables par l'administrateur).
     */
    private function geoSettings(): array
    {
        return [
            'arrival_radius_m' => (int) $this->settings->get('geo.arrival_radius_m', 300),
            'low_accuracy_m' => (int) $this->settings->get('geo.low_accuracy_m', 100),
            'position_stale_minutes' => (int) $this->settings->get('geo.position_stale_minutes', 10),
            'track_interval_seconds' => (int) $this->settings->get('geo.track_interval_seconds', 30),
        ];
    }

    /**
     * @param array        $location Ligne de homecare_locations (latitude, longitude, accuracy_m, captured_at)
     * @param float[]|null $home     [latitude, longitude] du domicile
     */
    private function positionView(array $location, ?array $home, array $settings): array
    {
        $latitude = (float) $location['latitude'];
        $longitude = (float) $location['longitude'];
        $accuracy = $location['accuracy_m'] !== null ? (float) $location['accuracy_m'] : null;
        $captured = new \DateTimeImmutable((string) $location['captured_at'], new \DateTimeZone('UTC'));
        $age = Clock::now()->getTimestamp() - $captured->getTimestamp();
        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy_m' => $accuracy,
            'captured_at' => Clock::toIso((string) $location['captured_at']),
            'imprecise' => $accuracy !== null && $accuracy > $settings['low_accuracy_m'],
            'stale' => $age > $settings['position_stale_minutes'] * 60,
            'distance_m' => $home !== null ? (int) round(Geo::distance($latitude, $longitude, $home[0], $home[1])) : null,
        ];
    }

    /**
     * Synthèse pour la fiche : départ, arrivée (contrôle de distance au domicile), dernière position de l'équipe.
     */
    private function geoSummary(array $row): array
    {
        $settings = $this->geoSettings();
        $home = $row['latitude'] !== null ? [(float) $row['latitude'], (float) $row['longitude']] : null;
        $intervention = $this->activeIntervention((int) $row['id']);
        $pick = function (?string $kind) use ($intervention): ?array {
            if ($intervention === null) {
                return null;
            }
            $sql = 'SELECT latitude, longitude, accuracy_m, captured_at FROM homecare_locations WHERE intervention_id = ?'
                . ($kind !== null ? ' AND kind = ?' : '') . ' ORDER BY captured_at DESC, id DESC LIMIT 1';
            return $this->db->fetchOne($sql, $kind !== null ? [(int) $intervention['id'], $kind] : [(int) $intervention['id']]);
        };
        $departure = $pick('DEPART');
        $arrival = $pick('ARRIVEE');
        $last = in_array($row['status'], ['EN_ROUTE', 'SUR_PLACE', 'EN_COURS'], true) ? $pick(null) : null;

        $arrivalView = $arrival !== null ? $this->positionView($arrival, $home, $settings) : null;
        if ($arrivalView !== null) {
            unset($arrivalView['stale']);
            // Signalement informatif : la marge d'imprécision du GPS est déduite avant comparaison.
            $arrivalView['far'] = $arrivalView['distance_m'] !== null
                && $arrivalView['distance_m'] - ($arrivalView['accuracy_m'] ?? 0) > $settings['arrival_radius_m'];
        }
        $departureView = $departure !== null ? $this->positionView($departure, $home, $settings) : null;
        if ($departureView !== null) {
            unset($departureView['stale']);
        }
        return [
            'home_imprecise' => $row['gps_accuracy_m'] !== null && (float) $row['gps_accuracy_m'] > $settings['low_accuracy_m'],
            'departure' => $departureView,
            'arrival' => $arrivalView,
            'team_position' => $last !== null ? $this->positionView($last, $home, $settings) : null,
            'settings' => $settings,
        ];
    }

    /**
     * Dernière position de l'équipe en route et domicile (le sien) : le patient suit l'approche et
     * l'application estime l'heure d'arrivée. Aucune autre donnée de l'équipe (ni trajet complet).
     */
    private function approachView(array $row, ?array $intervention): ?array
    {
        if ($intervention === null) {
            return null;
        }
        $last = $this->db->fetchOne(
            'SELECT latitude, longitude, accuracy_m, captured_at FROM homecare_locations WHERE intervention_id = ? ORDER BY captured_at DESC, id DESC LIMIT 1',
            [(int) $intervention['id']]
        );
        if ($last === null) {
            return null;
        }
        $home = $row['latitude'] !== null ? [(float) $row['latitude'], (float) $row['longitude']] : null;
        return $this->positionView($last, $home, $this->geoSettings()) + [
            'home' => $home !== null ? ['latitude' => $home[0], 'longitude' => $home[1]] : null,
        ];
    }

    private function storePatientHomePosition(array $row, array $data, string $capturedAt, AuthContext $auth, Request $request): void
    {
        $patientId = (int) $row['patient_id'];
        $address = $this->db->fetchOne(
            'SELECT * FROM patient_addresses WHERE patient_id = ? AND deleted_at IS NULL ORDER BY is_primary DESC, id LIMIT 1',
            [$patientId]
        );
        $gps = [
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'gps_accuracy_m' => $data['accuracy_m'] ?? null,
            'gps_captured_at' => $capturedAt,
        ];
        if ($address !== null) {
            $this->db->update('patient_addresses', $gps + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $address['id']]);
            $this->db->execute('UPDATE patient_addresses SET version = version + 1 WHERE id = ?', [(int) $address['id']]);
            $this->journal->record('patient_address', (string) $address['uuid'], ChangeJournal::UPSERT, $patientId);
            $addressUuid = (string) $address['uuid'];
        } else {
            $created = $this->patientChildren->insert('addresses', $patientId, $gps + [
                'label' => 'Domicile',
                'address_line' => $row['address_text'],
                'landmark' => $data['landmark'] ?? $row['landmark'],
                'is_primary' => 1,
            ], $auth->userId());
            $addressUuid = (string) $created['id'];
        }
        $this->audit->record(
            'PATIENT_ADDRESS_GPS_UPDATED',
            $request,
            'patient_address',
            $addressUuid,
            $address !== null ? ['latitude' => $address['latitude'], 'longitude' => $address['longitude']] : null,
            ['latitude' => $data['latitude'], 'longitude' => $data['longitude'], 'source' => 'homecare_visit']
        );
    }

    // ------------------------------------------------------------------ Présentation et droits

    /**
     * Niveau de visibilité : 'patient', 'full', 'minimal' (statut seul, pour retirer la demande de la
     * file des autres équipes) ou null (aucun accès).
     */
    public function access(AuthContext $auth, array $row): ?string
    {
        if ($auth->isPatient()) {
            $patient = (array) $this->patients->findById((int) $row['patient_id']);
            return $this->policy->owns($auth, $patient) ? 'patient' : null;
        }
        if ($auth->can('homecare.dispatch') || (int) $row['requested_by'] === $auth->userId()) {
            return 'full';
        }
        if (!$auth->can('homecare.intervene')) {
            return null;
        }
        if ($row['assigned_team_id'] !== null && $this->teams->isActiveMember($auth->userId(), (int) $row['assigned_team_id'])) {
            return 'full';
        }
        if ($row['status'] === 'EN_ATTENTE' && $this->selfAssignAllowed()) {
            return 'full';
        }
        return 'minimal';
    }

    public function present(array $row, string $level, bool $detailed): array
    {
        $base = [
            'id' => (string) $row['uuid'],
            'status' => (string) $row['status'],
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if ($level === 'minimal') {
            return $base + ['available' => false];
        }
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $team = $row['assigned_team_id'] !== null
            ? $this->db->fetchOne('SELECT uuid, label FROM teams WHERE id = ?', [(int) $row['assigned_team_id']])
            : null;
        $intervention = $this->activeIntervention((int) $row['id']);
        $consultationUuid = $this->db->fetchValue(
            "SELECT uuid FROM consultations WHERE homecare_request_id = ? AND status <> 'ANNULEE' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
            [(int) $row['id']]
        );
        $item = $base + [
            'patient_id' => (string) $patient['uuid'],
            'reason' => (string) $row['reason'],
            'urgency' => (string) $row['urgency'],
            'preferred_time' => Clock::toIso($row['preferred_time']),
            'address_text' => $row['address_text'],
            'landmark' => $row['landmark'],
            'team' => $team !== null ? ['id' => (string) $team['uuid'], 'label' => (string) $team['label']] : null,
            'accepted_at' => $intervention !== null ? Clock::toIso((string) $intervention['accepted_at']) : null,
            'departed_at' => $intervention !== null ? Clock::toIso($intervention['departed_at']) : null,
            'arrived_at' => $intervention !== null ? Clock::toIso($intervention['arrived_at']) : null,
            'cancel_reason' => $row['cancel_reason'],
            'closed_at' => Clock::toIso($row['closed_at']),
            'created_at' => Clock::toIso((string) $row['created_at']),
        ];
        if ($level === 'patient') {
            // Équipe en route vers son domicile : le patient voit où elle se trouve (comme pour un taxi)
            // pour se tenir prêt. Seulement pendant ce trajet, ni avant le départ ni après l'arrivée.
            $item['team_approach'] = $row['status'] === 'EN_ROUTE' ? $this->approachView($row, $intervention) : null;
            return $item;
        }
        $requester = $this->db->fetchOne('SELECT uuid, first_name, last_name, account_type FROM users WHERE id = ?', [(int) $row['requested_by']]);
        $item += [
            'patient' => [
                'id' => (string) $patient['uuid'],
                'file_number' => $patient['file_number'],
                'name' => $patient['first_name'] . ' ' . $patient['last_name'],
                'sex' => (string) $patient['sex'],
                'birth_date' => $patient['birth_date'],
            ],
            'location' => $row['latitude'] !== null ? [
                'latitude' => (float) $row['latitude'],
                'longitude' => (float) $row['longitude'],
                'accuracy_m' => $row['gps_accuracy_m'] !== null ? (float) $row['gps_accuracy_m'] : null,
                'captured_at' => Clock::toIso($row['gps_captured_at']),
            ] : null,
            'contact_phone' => (string) $row['contact_phone'],
            'requested_by' => $requester !== null ? [
                'id' => (string) $requester['uuid'],
                'name' => $requester['first_name'] . ' ' . $requester['last_name'],
                'is_patient' => $requester['account_type'] === 'PATIENT',
            ] : null,
            'assigned_at' => Clock::toIso($row['assigned_at']),
            'intervention_id' => $intervention !== null ? (string) $intervention['uuid'] : null,
            'started_at' => $intervention !== null ? Clock::toIso($intervention['started_at']) : null,
            'completed_at' => $intervention !== null ? Clock::toIso($intervention['completed_at']) : null,
            'consultation_id' => $consultationUuid !== null ? (string) $consultationUuid : null,
            'failure_reason' => $row['failure_reason'],
        ];
        if ($detailed) {
            $item['geo'] = $this->geoSummary($row);
            $item['history'] = array_map(function (array $entry): array {
                return [
                    'from' => $entry['from_status'],
                    'to' => (string) $entry['to_status'],
                    'at' => Clock::toIso((string) $entry['changed_at']),
                    'received_at' => Clock::toIso((string) $entry['received_at']),
                    'by' => ['id' => (string) $entry['user_uuid'], 'name' => $entry['first_name'] . ' ' . $entry['last_name']],
                    'latitude' => $entry['latitude'] !== null ? (float) $entry['latitude'] : null,
                    'longitude' => $entry['longitude'] !== null ? (float) $entry['longitude'] : null,
                    'comment' => $entry['comment'],
                ];
            }, $this->db->fetchAll(
                'SELECT h.*, u.uuid AS user_uuid, u.first_name, u.last_name FROM homecare_status_history h JOIN users u ON u.id = h.changed_by
                 WHERE h.request_id = ? ORDER BY h.id',
                [(int) $row['id']]
            ));
        }
        return $item;
    }

    public function findOrFail(string $uuid): array
    {
        $row = $this->db->fetchOne('SELECT * FROM homecare_requests WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            throw HttpException::notFound('Demande introuvable.');
        }
        return $row;
    }

    // ------------------------------------------------------------------ Interne

    private function startIntervention(array $row, int $teamId, AuthContext $auth, array $data): void
    {
        $now = Clock::nowForDatabase();
        // Garde-fou de concurrence : la mise à jour ne s'applique que si l'état n'a pas changé depuis la lecture.
        $updated = $this->db->execute(
            "UPDATE homecare_requests SET status = 'PRISE_EN_CHARGE', assigned_team_id = ?, assigned_by = ?, assigned_at = ?,
                    updated_by = ?, updated_at = ?, version = version + 1
             WHERE id = ? AND status = ?",
            [$teamId, $auth->userId(), $now, $auth->userId(), $now, (int) $row['id'], (string) $row['status']]
        );
        if ($updated === 0) {
            throw HttpException::conflict('Cette visite vient d\'être prise en charge par une autre équipe.', 'INVALID_TRANSITION');
        }
        $this->db->insert('homecare_interventions', [
            'uuid' => Uuid::v4(),
            'request_id' => (int) $row['id'],
            'team_id' => $teamId,
            'accepted_by' => $auth->userId(),
            'accepted_at' => $now,
            'created_by' => $auth->userId(),
            'updated_by' => $auth->userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->history((int) $row['id'], (string) $row['status'], 'PRISE_EN_CHARGE', $auth, $data['at'] ?? $now, $data, $data['comment'] ?? null);
        // L'équipe doit disposer du dossier complet hors ligne, même avec un curseur de synchronisation avancé.
        $this->snapshot->record((int) $row['patient_id']);
        $this->notifyPatient($row, 'Votre demande de visite à domicile a été prise en charge par une équipe.');
    }

    private function releaseIntervention(int $requestId, string $reason): void
    {
        $now = Clock::nowForDatabase();
        $this->db->execute(
            'UPDATE homecare_interventions SET released_at = ?, release_reason = ?, updated_at = ?, version = version + 1 WHERE request_id = ? AND released_at IS NULL',
            [$now, $reason, $now, $requestId]
        );
    }

    private function setStatus(array $row, string $to, array $changes, AuthContext $auth, ?array $data, ?string $comment = null): void
    {
        $now = Clock::nowForDatabase();
        $this->db->update('homecare_requests', ['status' => $to] + $changes + ['updated_by' => $auth->userId(), 'updated_at' => $now], 'id = ?', [(int) $row['id']]);
        $this->db->execute('UPDATE homecare_requests SET version = version + 1 WHERE id = ?', [(int) $row['id']]);
        $this->history((int) $row['id'], (string) $row['status'], $to, $auth, $data['at'] ?? $now, $data, $comment ?? ($data['comment'] ?? null));
    }

    /**
     * Relit la demande, alimente le journal de synchronisation et l'audit, puis renvoie la vue de l'acteur.
     *
     * @param int|null $previousTeam Équipe à informer en plus de l'équipe actuelle (désistement, réaffectation)
     * @param bool     $queueChanged La demande entre dans la file d'attente ou en sort : tous les intervenants sont informés
     */
    private function finish(array $before, ?int $previousTeam, bool $queueChanged, string $action, AuthContext $auth, Request $request): array
    {
        $row = (array) $this->db->fetchOne('SELECT * FROM homecare_requests WHERE id = ?', [(int) $before['id']]);
        $this->record($row, $previousTeam, $queueChanged);
        $this->audit->record($action, $request, 'homecare_request', (string) $row['uuid'], ['status' => $before['status']], ['status' => $row['status']]);
        return $this->present($row, (string) ($this->access($auth, $row) ?? 'minimal'), true);
    }

    private function record(array $row, ?int $previousTeam, bool $queueChanged): void
    {
        $uuid = (string) $row['uuid'];
        $team = $row['assigned_team_id'] !== null ? (int) $row['assigned_team_id'] : null;
        $this->journal->record('homecare_request', $uuid, ChangeJournal::UPSERT, (int) $row['patient_id'], $team);
        if ($previousTeam !== null && $previousTeam !== $team) {
            $this->journal->record('homecare_request', $uuid, ChangeJournal::UPSERT, null, $previousTeam);
        }
        if ($queueChanged) {
            // Portée globale : chaque appareil reçoit l'élément ; current() ne renvoie que ce que l'utilisateur peut voir.
            $this->journal->record('homecare_request', $uuid, ChangeJournal::UPSERT);
        }
    }

    private function history(int $requestId, ?string $from, string $to, AuthContext $auth, string $at, ?array $data = null, ?string $comment = null): void
    {
        $this->db->insert('homecare_status_history', [
            'uuid' => Uuid::v4(),
            'request_id' => $requestId,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $auth->userId(),
            'changed_at' => $at,
            'received_at' => Clock::nowForDatabase(),
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'comment' => $comment,
            'device_id' => $auth->deviceId(),
        ]);
    }

    private function location(int $interventionId, string $kind, array $data, AuthContext $auth): void
    {
        $this->db->insert('homecare_locations', [
            'uuid' => Uuid::v4(),
            'intervention_id' => $interventionId,
            'kind' => $kind,
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'captured_at' => $data['at'] ?? Clock::nowForDatabase(),
            'recorded_by' => $auth->userId(),
            'created_at' => Clock::nowForDatabase(),
        ]);
    }

    private function activeIntervention(int $requestId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM homecare_interventions WHERE request_id = ? AND released_at IS NULL ORDER BY id DESC LIMIT 1',
            [$requestId]
        );
    }

    private function resolveOwnTeam(AuthContext $auth, ?string $teamUuid): int
    {
        $teamIds = $this->teams->activeMobileTeamIds($auth->userId());
        if ($teamIds === []) {
            throw HttpException::forbidden('Vous devez appartenir à une équipe mobile active.', 'NOT_TEAM_MEMBER');
        }
        if ($teamUuid === null) {
            if (count($teamIds) > 1) {
                throw new ValidationException(['team_id' => ['Vous appartenez à plusieurs équipes : précisez laquelle intervient.']]);
            }
            return $teamIds[0];
        }
        $teamId = $this->db->fetchValue('SELECT id FROM teams WHERE uuid = ?', [$teamUuid]);
        if ($teamId === null || !in_array((int) $teamId, $teamIds, true)) {
            throw new ValidationException(['team_id' => ['Vous n\'êtes pas membre de cette équipe mobile.']]);
        }
        return (int) $teamId;
    }

    private function assertTeamMember(AuthContext $auth, array $row): void
    {
        if ($row['assigned_team_id'] === null || !$this->teams->isActiveMember($auth->userId(), (int) $row['assigned_team_id'])) {
            throw HttpException::forbidden('Seuls les membres de l\'équipe affectée peuvent faire évoluer cette visite.', 'NOT_TEAM_MEMBER');
        }
    }

    private function notifyPatient(array $row, string $body): void
    {
        $patient = $this->patients->findById((int) $row['patient_id']);
        if ($patient !== null && $patient['user_id'] !== null) {
            $this->notifications->notify((int) $patient['user_id'], 'HOMECARE_STATUS', 'Visite à domicile', $body, 'homecare_request', (string) $row['uuid']);
        }
    }

    private function notifyTeam(int $teamId, string $requestUuid, string $title = 'Nouvelle visite à domicile', string $body = 'Une visite à domicile a été affectée à votre équipe.'): void
    {
        $today = Clock::now()->format('Y-m-d');
        $members = $this->db->fetchAll(
            'SELECT DISTINCT user_id FROM team_members WHERE team_id = ? AND from_date <= ? AND (to_date IS NULL OR to_date >= ?)',
            [$teamId, $today, $today]
        );
        foreach ($members as $member) {
            $this->notifications->notify((int) $member['user_id'], 'HOMECARE_ASSIGNED', $title, $body, 'homecare_request', $requestUuid);
        }
    }

    private function lock(string $uuid): array
    {
        $row = $this->db->fetchOne('SELECT * FROM homecare_requests WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($row === null) {
            throw HttpException::notFound('Demande introuvable.');
        }
        return $row;
    }

    private function dispatchMode(): string
    {
        $mode = (string) $this->settings->get('homecare.dispatch_mode', 'BOTH');
        return in_array($mode, ['BOTH', 'SELF_ASSIGN', 'DISPATCH_ONLY'], true) ? $mode : 'BOTH';
    }

    private function selfAssignAllowed(): bool
    {
        return $this->dispatchMode() !== 'DISPATCH_ONLY';
    }

    /**
     * Champs communs aux actions de terrain : heure de l'action sur l'appareil, position, commentaire.
     */
    private static function fieldRules(): array
    {
        return [
            'at' => 'nullable|datetime',
            'latitude' => 'nullable|latitude',
            'longitude' => 'nullable|longitude',
            'accuracy_m' => 'nullable|numeric|min:0|max:100000',
            'comment' => 'nullable|string|max:500',
        ];
    }

    private static function assertCoordinates(array $data): void
    {
        if (isset($data['latitude']) !== isset($data['longitude'])) {
            throw new ValidationException(['longitude' => ['Latitude et longitude vont ensemble.']]);
        }
        Geo::assertUsable($data);
        if (isset($data['at'])) {
            ConsultationService::assertNotFuture($data['at'], 'at');
        }
    }

    private static function assertFrom(array $row, array $allowed): void
    {
        if (!in_array($row['status'], $allowed, true)) {
            throw HttpException::conflict(
                sprintf('Action impossible : la visite est à l\'état %s.', $row['status']),
                'INVALID_TRANSITION'
            );
        }
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
