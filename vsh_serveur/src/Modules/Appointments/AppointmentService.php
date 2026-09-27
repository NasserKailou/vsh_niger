<?php

declare(strict_types=1);

namespace Vsh\Modules\Appointments;

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
use Vsh\Modules\Notifications\NotificationService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Settings\SettingsService;

/**
 * Rendez-vous (rapport C3).
 *
 *   Patient : DEMANDE ──confirm (accueil)──▶ CONFIRME ──▶ HONORE (consultation liée) | ABSENT
 *   L'accueil crée directement un rendez-vous CONFIRME. reschedule ──▶ DEPLACE (reste actif).
 *   ANNULE : par le patient jusqu'à `appointments.patient_cancel_min_hours` avant, par l'accueil avec motif.
 *
 * Les créneaux sont CALCULÉS par le serveur : plages horaires configurées du service (heure locale de la
 * clinique) moins les rendez-vous actifs, dans la limite de la capacité par créneau.
 */
final class AppointmentService
{
    /** États qui occupent un créneau. */
    public const ACTIVE_STATUSES = ['DEMANDE', 'CONFIRME', 'DEPLACE', 'HONORE'];

    /** États encore modifiables (à venir). */
    public const PENDING_STATUSES = ['DEMANDE', 'CONFIRME', 'DEPLACE'];

    /** @var Database */
    private $db;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var SettingsService */
    private $settings;

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
        SettingsService $settings,
        NotificationService $notifications,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->settings = $settings;
        $this->notifications = $notifications;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    // ------------------------------------------------------------------ Créneaux

    /**
     * Créneaux d'un service pour une date locale. Aucune donnée personnelle : seulement la disponibilité.
     */
    public function slots(array $query, Request $request): array
    {
        $auth = self::auth($request);
        if (!$auth->can('appointments.read') && !$auth->can('appointments.request_self')) {
            throw HttpException::forbidden();
        }
        $data = $this->validator->validate($query, [
            'service_id' => 'required|uuid',
            'date' => 'required|date',
        ]);
        $service = $this->bookableService($data['service_id']);
        $slots = [];
        foreach ($this->slotsFor((int) $service['id'], $data['date']) as $slot) {
            $slots[] = [
                'start' => Clock::toIso($slot['start']),
                'end' => Clock::toIso($slot['end']),
                'local_time' => $slot['local_time'],
                'capacity' => $slot['capacity'],
                'available' => max(0, $slot['capacity'] - $slot['booked']),
            ];
        }
        return [
            'service' => ['id' => (string) $service['uuid'], 'label' => (string) $service['label']],
            'date' => $data['date'],
            'timezone' => $this->timezone()->getName(),
            'slots' => $slots,
        ];
    }

    // ------------------------------------------------------------------ Lecture

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($query, [
            'date' => 'nullable|date',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
            'service_id' => 'nullable|uuid',
            'status' => 'nullable|in:DEMANDE,CONFIRME,DEPLACE,ANNULE,HONORE,ABSENT',
            'patient_id' => 'nullable|uuid',
            'mine' => 'nullable|boolean',
        ]);
        $where = ['a.deleted_at IS NULL'];
        $params = [];
        // Hors accueil / administration : les rendez-vous dont on est le praticien.
        if (!$auth->can('appointments.manage') || !empty($data['mine'])) {
            $where[] = 'a.practitioner_id = ?';
            $params[] = $auth->userId();
        }
        $from = $data['from'] ?? ($data['date'] ?? null);
        $to = $data['to'] ?? ($data['date'] ?? null);
        if ($from !== null) {
            $where[] = 'a.scheduled_start >= ?';
            $params[] = $this->localDayBoundaryUtc($from, false);
        }
        if ($to !== null) {
            $where[] = 'a.scheduled_start < ?';
            $params[] = $this->localDayBoundaryUtc($to, true);
        }
        if (isset($data['service_id'])) {
            $where[] = 's.uuid = ?';
            $params[] = $data['service_id'];
        }
        if (isset($data['status'])) {
            $where[] = 'a.status = ?';
            $params[] = $data['status'];
        }
        if (isset($data['patient_id'])) {
            $where[] = 'p.uuid = ?';
            $params[] = $data['patient_id'];
        }
        $sql = ' FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN services s ON s.id = a.service_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT a.*' . $sql . ' ORDER BY a.scheduled_start, a.id LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row): array {
            return $this->present($row, false);
        }, $rows), $total];
    }

    public function get(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $row = $this->findOrFail($uuid);
        if (!$this->canSee($auth, $row)) {
            throw HttpException::notFound('Rendez-vous introuvable.');
        }
        return $this->present($row, $auth->isPatient());
    }

    public function forOwnPatient(string $patientUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM appointments WHERE patient_id = ? AND deleted_at IS NULL ORDER BY scheduled_start DESC LIMIT 100',
            [(int) $patient['id']]
        );
        return array_map(function (array $row): array {
            return $this->present($row, true);
        }, $rows);
    }

    public function canSee(AuthContext $auth, array $row): bool
    {
        if ($auth->isPatient()) {
            return $this->policy->owns($auth, (array) $this->patients->findById((int) $row['patient_id']));
        }
        if ($auth->can('appointments.manage')) {
            return true;
        }
        return $auth->can('appointments.read') && $row['practitioner_id'] !== null && (int) $row['practitioner_id'] === $auth->userId();
    }

    // ------------------------------------------------------------------ Prise de rendez-vous

    public function create(array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'service_id' => 'required|uuid',
            'scheduled_start' => 'required|datetime',
            'practitioner_id' => 'nullable|uuid',
            'reason' => 'nullable|string|max:500',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        if ($auth->isPatient()) {
            if (!$auth->can('appointments.request_self') || !$this->policy->owns($auth, $patient)) {
                throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
            }
            if (isset($data['practitioner_id'])) {
                throw new ValidationException(['practitioner_id' => ['Le praticien est choisi par la clinique.']]);
            }
        } elseif (!$auth->can('appointments.manage')) {
            throw HttpException::forbidden();
        }
        // Un dossier en attente de validation peut demander un rendez-vous : l'accueil le validera à la visite.
        if (!in_array($patient['status'], ['ACTIVE', 'PENDING'], true)) {
            throw HttpException::conflict('Ce dossier ne permet pas de prendre rendez-vous.', 'PATIENT_NOT_ACTIVE');
        }
        $service = $this->bookableService($data['service_id']);
        $practitionerId = isset($data['practitioner_id']) ? $this->practitionerId($data['practitioner_id']) : null;
        if ($uuid !== null && $this->db->fetchValue('SELECT id FROM appointments WHERE uuid = ?', [$uuid]) !== null) {
            throw HttpException::conflict('Ce rendez-vous existe déjà.', 'ALREADY_EXISTS');
        }
        $byPatient = $auth->isPatient();

        return $this->db->transaction(function () use ($uuid, $patient, $service, $practitionerId, $data, $byPatient, $auth, $request): array {
            $slot = $this->reserve((int) $service['id'], $data['scheduled_start'], (int) $patient['id'], null);
            $uuid = $uuid ?? Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('appointments', [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'service_id' => (int) $service['id'],
                'practitioner_id' => $practitionerId,
                'scheduled_start' => $slot['start'],
                'scheduled_end' => $slot['end'],
                'reason' => $data['reason'] ?? null,
                'status' => $byPatient ? 'DEMANDE' : 'CONFIRME',
                'requested_by' => $auth->userId(),
                'confirmed_by' => $byPatient ? null : $auth->userId(),
                'confirmed_at' => $byPatient ? null : $now,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $row = (array) $this->db->fetchOne('SELECT * FROM appointments WHERE id = ?', [$id]);
            $this->record($row);
            $this->audit->record('APPOINTMENT_' . ($byPatient ? 'REQUESTED' : 'BOOKED'), $request, 'appointment', $uuid, null, ['start' => $slot['start']]);
            if (!$byPatient) {
                $this->notify($row, 'Rendez-vous confirmé', 'Votre rendez-vous du ' . $this->localLabel($slot['start']) . ' est confirmé.');
            }
            return $this->present($row, $byPatient);
        });
    }

    public function confirm(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'appointments.manage');
        $data = $this->validator->validate($input, ['practitioner_id' => 'nullable|uuid']);
        $practitionerId = isset($data['practitioner_id']) ? $this->practitionerId($data['practitioner_id']) : null;
        return $this->db->transaction(function () use ($uuid, $practitionerId, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['DEMANDE', 'DEPLACE']);
            $changes = ['status' => 'CONFIRME', 'confirmed_by' => $auth->userId(), 'confirmed_at' => Clock::nowForDatabase()];
            if ($practitionerId !== null) {
                $changes['practitioner_id'] = $practitionerId;
            }
            $row = $this->apply($row, $changes, $auth, 'APPOINTMENT_CONFIRMED', $request);
            $this->notify($row, 'Rendez-vous confirmé', 'Votre rendez-vous du ' . $this->localLabel((string) $row['scheduled_start']) . ' est confirmé.');
            return $this->present($row, false);
        });
    }

    /**
     * Déplacement par l'accueil vers un autre créneau disponible du même service.
     */
    public function reschedule(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'appointments.manage');
        $data = $this->validator->validate($input, [
            'scheduled_start' => 'required|datetime',
            'practitioner_id' => 'nullable|uuid',
        ]);
        $practitionerId = isset($data['practitioner_id']) ? $this->practitionerId($data['practitioner_id']) : null;
        return $this->db->transaction(function () use ($uuid, $data, $practitionerId, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, self::PENDING_STATUSES);
            $slot = $this->reserve((int) $row['service_id'], $data['scheduled_start'], (int) $row['patient_id'], (int) $row['id']);
            $changes = [
                'status' => 'DEPLACE',
                'scheduled_start' => $slot['start'],
                'scheduled_end' => $slot['end'],
                'rescheduled_from' => $row['rescheduled_from'] ?? $row['scheduled_start'],
                'checked_in_at' => null,
            ];
            if ($practitionerId !== null) {
                $changes['practitioner_id'] = $practitionerId;
            }
            $row = $this->apply($row, $changes, $auth, 'APPOINTMENT_RESCHEDULED', $request);
            $this->notify($row, 'Rendez-vous déplacé', 'Votre rendez-vous est déplacé au ' . $this->localLabel($slot['start']) . '.');
            return $this->present($row, false);
        });
    }

    /**
     * Annulation : par le patient jusqu'au délai paramétré, par l'accueil avec motif.
     */
    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        return $this->db->transaction(function () use ($uuid, $input, $auth, $request): array {
            $row = $this->lock($uuid);
            if ($auth->isPatient()) {
                if (!$auth->can('appointments.request_self') || !$this->canSee($auth, $row)) {
                    throw HttpException::notFound('Rendez-vous introuvable.');
                }
                $data = $this->validator->validate($input, ['reason' => 'nullable|string|max:500']);
                self::assertFrom($row, self::PENDING_STATUSES);
                $minHours = max(0, (int) $this->settings->get('appointments.patient_cancel_min_hours', 24));
                $limit = Clock::now()->modify('+' . $minHours . ' hours')->format('Y-m-d H:i:s');
                if ((string) $row['scheduled_start'] < $limit) {
                    throw HttpException::conflict(
                        sprintf('Annulation possible jusqu\'à %d heures avant le rendez-vous : contactez la clinique.', $minHours),
                        'CANCEL_TOO_LATE'
                    );
                }
            } else {
                self::require($auth, 'appointments.manage');
                $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
                self::assertFrom($row, self::PENDING_STATUSES);
            }
            $row = $this->apply($row, [
                'status' => 'ANNULE',
                'cancelled_by' => $auth->userId(),
                'cancelled_at' => Clock::nowForDatabase(),
                'cancel_reason' => $data['reason'] ?? null,
            ], $auth, 'APPOINTMENT_CANCELLED', $request);
            if (!$auth->isPatient()) {
                $this->notify($row, 'Rendez-vous annulé', 'Votre rendez-vous du ' . $this->localLabel((string) $row['scheduled_start']) . ' a été annulé par la clinique.');
            }
            return $this->present($row, $auth->isPatient());
        });
    }

    /** Arrivée du patient à l'accueil (le jour du rendez-vous). */
    public function checkIn(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'appointments.manage');
        return $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['CONFIRME', 'DEPLACE']);
            if ($this->localDate((string) $row['scheduled_start']) !== $this->localDate(Clock::nowForDatabase())) {
                throw HttpException::conflict('L\'arrivée s\'enregistre le jour du rendez-vous.', 'NOT_TODAY');
            }
            if ($row['checked_in_at'] !== null) {
                return $this->present($row, false);
            }
            return $this->present($this->apply($row, ['checked_in_at' => Clock::nowForDatabase()], $auth, 'APPOINTMENT_CHECKED_IN', $request), false);
        });
    }

    /** Patient absent : seulement une fois l'heure du rendez-vous passée, et s'il n'est pas arrivé. */
    public function noShow(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        self::require($auth, 'appointments.manage');
        return $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $row = $this->lock($uuid);
            self::assertFrom($row, ['CONFIRME', 'DEPLACE']);
            if ((string) $row['scheduled_start'] > Clock::nowForDatabase()) {
                throw HttpException::conflict('Le rendez-vous n\'a pas encore eu lieu.', 'NOT_YET');
            }
            if ($row['checked_in_at'] !== null) {
                throw HttpException::conflict('Le patient a été enregistré à l\'accueil.', 'CHECKED_IN');
            }
            return $this->present($this->apply($row, ['status' => 'ABSENT'], $auth, 'APPOINTMENT_NO_SHOW', $request), false);
        });
    }

    // ------------------------------------------------------------------ Présentation

    public function present(array $row, bool $forPatient): array
    {
        $service = (array) $this->db->fetchOne('SELECT uuid, label FROM services WHERE id = ?', [(int) $row['service_id']]);
        $practitioner = $row['practitioner_id'] !== null
            ? $this->db->fetchOne('SELECT uuid, first_name, last_name FROM users WHERE id = ?', [(int) $row['practitioner_id']])
            : null;
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $item = [
            'id' => (string) $row['uuid'],
            'patient_id' => (string) $patient['uuid'],
            'service' => ['id' => (string) $service['uuid'], 'label' => (string) $service['label']],
            'practitioner' => $practitioner !== null ? ['id' => (string) $practitioner['uuid'], 'name' => $practitioner['first_name'] . ' ' . $practitioner['last_name']] : null,
            'scheduled_start' => Clock::toIso((string) $row['scheduled_start']),
            'scheduled_end' => Clock::toIso((string) $row['scheduled_end']),
            'reason' => $row['reason'],
            'status' => (string) $row['status'],
            'confirmed_at' => Clock::toIso($row['confirmed_at']),
            'rescheduled_from' => Clock::toIso($row['rescheduled_from']),
            'cancelled_at' => Clock::toIso($row['cancelled_at']),
            'cancel_reason' => $row['cancel_reason'],
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if (!$forPatient) {
            $consultationUuid = $this->db->fetchValue(
                "SELECT uuid FROM consultations WHERE appointment_id = ? AND status <> 'ANNULEE' AND deleted_at IS NULL ORDER BY id DESC LIMIT 1",
                [(int) $row['id']]
            );
            $item += [
                'patient' => ['id' => (string) $patient['uuid'], 'file_number' => $patient['file_number'], 'name' => $patient['first_name'] . ' ' . $patient['last_name']],
                'checked_in_at' => Clock::toIso($row['checked_in_at']),
                'consultation_id' => $consultationUuid !== null ? (string) $consultationUuid : null,
                'requested_by_patient' => $this->db->fetchValue('SELECT account_type FROM users WHERE id = ?', [(int) $row['requested_by']]) === 'PATIENT',
            ];
        }
        return $item;
    }

    public function findOrFail(string $uuid): array
    {
        $row = $this->db->fetchOne('SELECT * FROM appointments WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            throw HttpException::notFound('Rendez-vous introuvable.');
        }
        return $row;
    }

    // ------------------------------------------------------------------ Interne

    /**
     * Vérifie que l'heure demandée correspond à un créneau configuré, dans l'horizon autorisé, avec de la
     * place, et que le patient n'a pas déjà un rendez-vous à la même heure. Les réservations d'un même
     * service sont sérialisées par un verrou sur la ligne du service.
     *
     * @return array{start: string, end: string}
     */
    private function reserve(int $serviceId, string $start, int $patientId, ?int $excludeId): array
    {
        $this->db->fetchOne('SELECT id FROM services WHERE id = ? FOR UPDATE', [$serviceId]);
        if ($start <= Clock::nowForDatabase()) {
            throw new ValidationException(['scheduled_start' => ['Choisissez un créneau à venir.']]);
        }
        $horizon = max(1, (int) $this->settings->get('appointments.max_days_ahead', 60));
        if ($this->localDate($start) > $this->localDate(Clock::now()->modify('+' . $horizon . ' days')->format('Y-m-d H:i:s'))) {
            throw new ValidationException(['scheduled_start' => [sprintf('Les rendez-vous se prennent au plus %d jours à l\'avance.', $horizon)]]);
        }
        $slot = null;
        foreach ($this->slotsFor($serviceId, $this->localDate($start), $excludeId) as $candidate) {
            if ($candidate['start'] === $start) {
                $slot = $candidate;
                break;
            }
        }
        if ($slot === null) {
            throw new ValidationException(['scheduled_start' => ['Cette heure ne correspond à aucun créneau du service.']]);
        }
        if ($slot['booked'] >= $slot['capacity']) {
            throw HttpException::conflict('Ce créneau est complet : choisissez-en un autre.', 'SLOT_FULL');
        }
        $params = [$patientId, $start];
        $sql = "SELECT 1 FROM appointments WHERE patient_id = ? AND scheduled_start = ? AND deleted_at IS NULL
                AND status IN ('" . implode("', '", self::ACTIVE_STATUSES) . "')";
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        if ($this->db->fetchValue($sql, $params) !== null) {
            throw HttpException::conflict('Le patient a déjà un rendez-vous à cette heure.', 'PATIENT_BUSY');
        }
        return ['start' => $slot['start'], 'end' => $slot['end']];
    }

    /**
     * Créneaux à venir (UTC) d'un service pour une date locale, avec le nombre de rendez-vous actifs.
     *
     * @return array<int, array{start: string, end: string, local_time: string, capacity: int, booked: int}>
     */
    private function slotsFor(int $serviceId, string $localDate, ?int $excludeId = null): array
    {
        $timezone = $this->timezone();
        $utc = new \DateTimeZone('UTC');
        $day = new \DateTimeImmutable($localDate . ' 00:00:00', $timezone);
        $schedules = $this->db->fetchAll(
            'SELECT * FROM service_schedules WHERE service_id = ? AND weekday = ? AND active = 1 ORDER BY start_time',
            [$serviceId, (int) $day->format('N')]
        );
        $slots = [];
        foreach ($schedules as $schedule) {
            $cursor = new \DateTimeImmutable($localDate . ' ' . $schedule['start_time'], $timezone);
            $end = new \DateTimeImmutable($localDate . ' ' . $schedule['end_time'], $timezone);
            $step = new \DateInterval('PT' . (int) $schedule['slot_minutes'] . 'M');
            while ($cursor->add($step) <= $end) {
                $slotEnd = $cursor->add($step);
                $key = $cursor->setTimezone($utc)->format('Y-m-d H:i:s');
                if (!isset($slots[$key])) {
                    $slots[$key] = [
                        'start' => $key,
                        'end' => $slotEnd->setTimezone($utc)->format('Y-m-d H:i:s'),
                        'local_time' => $cursor->format('H:i'),
                        'capacity' => (int) $schedule['capacity_per_slot'],
                        'booked' => 0,
                    ];
                }
                $cursor = $slotEnd;
            }
        }
        if ($slots === []) {
            return [];
        }
        $params = [$serviceId, $day->setTimezone($utc)->format('Y-m-d H:i:s'), $day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s')];
        $sql = "SELECT scheduled_start, COUNT(*) AS booked FROM appointments
                WHERE service_id = ? AND scheduled_start >= ? AND scheduled_start < ? AND deleted_at IS NULL
                  AND status IN ('" . implode("', '", self::ACTIVE_STATUSES) . "')";
        if ($excludeId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $excludeId;
        }
        foreach ($this->db->fetchAll($sql . ' GROUP BY scheduled_start', $params) as $row) {
            if (isset($slots[(string) $row['scheduled_start']])) {
                $slots[(string) $row['scheduled_start']]['booked'] = (int) $row['booked'];
            }
        }
        ksort($slots);
        // Les créneaux passés ne sont plus proposés.
        $now = Clock::nowForDatabase();
        return array_values(array_filter($slots, function (array $slot) use ($now): bool {
            return $slot['start'] > $now;
        }));
    }

    private function apply(array $row, array $changes, AuthContext $auth, string $action, Request $request): array
    {
        $this->db->update('appointments', $changes + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $row['id']]);
        $this->db->execute('UPDATE appointments SET version = version + 1 WHERE id = ?', [(int) $row['id']]);
        $updated = (array) $this->db->fetchOne('SELECT * FROM appointments WHERE id = ?', [(int) $row['id']]);
        $this->record($updated);
        if ($row['practitioner_id'] !== null && (string) $row['practitioner_id'] !== (string) $updated['practitioner_id']) {
            // L'ancien praticien doit voir le rendez-vous quitter son planning hors ligne.
            $this->journal->record('appointment', (string) $row['uuid'], ChangeJournal::UPSERT, null, null, (int) $row['practitioner_id']);
        }
        $this->audit->record($action, $request, 'appointment', (string) $row['uuid'], ['status' => $row['status']], ['status' => $updated['status']]);
        return $updated;
    }

    private function record(array $row): void
    {
        $this->journal->record(
            'appointment',
            (string) $row['uuid'],
            ChangeJournal::UPSERT,
            (int) $row['patient_id'],
            null,
            $row['practitioner_id'] !== null ? (int) $row['practitioner_id'] : null
        );
    }

    /**
     * Notification sans motif ni service (la spécialité peut être sensible) : date et heure seulement.
     */
    private function notify(array $row, string $title, string $body): void
    {
        $patient = $this->patients->findById((int) $row['patient_id']);
        if ($patient !== null && $patient['user_id'] !== null) {
            $this->notifications->notify((int) $patient['user_id'], 'APPOINTMENT_STATUS', $title, $body, 'appointment', (string) $row['uuid']);
        }
    }

    private function bookableService(string $uuid): array
    {
        $service = $this->db->fetchOne('SELECT * FROM services WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($service === null || !(bool) $service['active'] || !(bool) $service['accepts_appointments']) {
            throw new ValidationException(['service_id' => ['Service introuvable ou fermé aux rendez-vous.']]);
        }
        return $service;
    }

    private function practitionerId(string $uuid): int
    {
        $id = $this->db->fetchValue(
            "SELECT id FROM users WHERE uuid = ? AND account_type = 'STAFF' AND status = 'ACTIVE' AND deleted_at IS NULL",
            [$uuid]
        );
        if ($id === null) {
            throw new ValidationException(['practitioner_id' => ['Praticien introuvable ou inactif.']]);
        }
        return (int) $id;
    }

    private function lock(string $uuid): array
    {
        $row = $this->db->fetchOne('SELECT * FROM appointments WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($row === null) {
            throw HttpException::notFound('Rendez-vous introuvable.');
        }
        return $row;
    }

    private function localDayBoundaryUtc(string $localDate, bool $nextDay): string
    {
        $day = new \DateTimeImmutable($localDate . ' 00:00:00', $this->timezone());
        if ($nextDay) {
            $day = $day->modify('+1 day');
        }
        return $day->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function localDate(string $utcDateTime): string
    {
        return (new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')))->setTimezone($this->timezone())->format('Y-m-d');
    }

    private function localLabel(string $utcDateTime): string
    {
        return (new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')))->setTimezone($this->timezone())->format('d/m/Y à H\hi');
    }

    private function timezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
    }

    private static function assertFrom(array $row, array $allowed): void
    {
        if (!in_array($row['status'], $allowed, true)) {
            throw HttpException::conflict(sprintf('Action impossible : le rendez-vous est à l\'état %s.', $row['status']), 'INVALID_TRANSITION');
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
