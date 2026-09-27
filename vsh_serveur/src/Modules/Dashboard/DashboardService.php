<?php

declare(strict_types=1);

namespace Vsh\Modules\Dashboard;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Modules\Settings\SettingsService;
use Vsh\Modules\Teams\TeamService;

/**
 * Tableau de bord : des COMPTEURS agrégés, chacun conditionné par la permission du domaine concerné.
 * Aucun contenu médical (motif, diagnostic, résultat). Seul le planning personnel du praticien
 * nomme les patients, comme son agenda.
 */
final class DashboardService
{
    private const HOMECARE_OPEN = ['NOUVELLE', 'EN_ATTENTE', 'PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS'];

    /** @var Database */
    private $db;

    /** @var SettingsService */
    private $settings;

    public function __construct(Database $db, SettingsService $settings)
    {
        $this->db = $db;
        $this->settings = $settings;
    }

    public function summary(Request $request): array
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        if ($auth->isPatient() || (!$auth->can('dashboard.global') && !$auth->can('dashboard.personal'))) {
            throw HttpException::forbidden();
        }
        $timezone = new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
        $today = Clock::now()->setTimezone($timezone)->setTime(0, 0);
        $utc = new \DateTimeZone('UTC');
        $dayStart = $today->setTimezone($utc)->format('Y-m-d H:i:s');
        $dayEnd = $today->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
        $monthStart = $today->modify('first day of this month')->setTimezone($utc)->format('Y-m-d H:i:s');
        $userId = $auth->userId();

        $data = [
            'date' => $today->format('Y-m-d'),
            'timezone' => $timezone->getName(),
            'currency' => (string) $this->settings->get('app.currency', 'XOF'),
        ];

        if ($auth->can('dashboard.global')) {
            $data['activity'] = [
                'patients_active' => $this->count("SELECT COUNT(*) FROM patients WHERE status = 'ACTIVE' AND deleted_at IS NULL"),
                'patients_new_month' => $this->count('SELECT COUNT(*) FROM patients WHERE created_at >= ? AND deleted_at IS NULL', [$monthStart]),
                'consultations_today' => $this->count(
                    "SELECT COUNT(*) FROM consultations WHERE started_at >= ? AND started_at < ? AND status <> 'ANNULEE' AND deleted_at IS NULL",
                    [$dayStart, $dayEnd]
                ),
                'treatments_today' => $this->count(
                    "SELECT COUNT(*) FROM treatments WHERE status = 'REALISE' AND performed_at >= ? AND performed_at < ? AND deleted_at IS NULL",
                    [$dayStart, $dayEnd]
                ),
            ];
        }
        if ($auth->can('patients.validate_registration')) {
            $data['registrations_pending'] = $this->count("SELECT COUNT(*) FROM patients WHERE status = 'PENDING' AND deleted_at IS NULL");
        }
        if ($auth->can('appointments.manage')) {
            $data['appointments_today'] = $this->byStatus(
                'SELECT status, COUNT(*) AS n FROM appointments WHERE scheduled_start >= ? AND scheduled_start < ? AND deleted_at IS NULL GROUP BY status',
                [$dayStart, $dayEnd]
            );
            $data['appointments_to_confirm'] = $this->count(
                "SELECT COUNT(*) FROM appointments WHERE status = 'DEMANDE' AND scheduled_start >= ? AND deleted_at IS NULL",
                [Clock::nowForDatabase()]
            );
        }
        // Planning personnel : praticiens seulement (l'accueil et l'administration voient le planning global).
        if ($auth->can('appointments.read') && !$auth->can('appointments.manage')) {
            $data['my_agenda'] = array_map(function (array $row): array {
                return [
                    'id' => (string) $row['uuid'],
                    'scheduled_start' => Clock::toIso((string) $row['scheduled_start']),
                    'status' => (string) $row['status'],
                    'patient' => ['id' => (string) $row['patient_uuid'], 'file_number' => $row['file_number'], 'name' => $row['first_name'] . ' ' . $row['last_name']],
                    'service' => (string) $row['service_label'],
                ];
            }, $this->db->fetchAll(
                "SELECT a.uuid, a.scheduled_start, a.status, p.uuid AS patient_uuid, p.file_number, p.first_name, p.last_name, s.label AS service_label
                 FROM appointments a JOIN patients p ON p.id = a.patient_id JOIN services s ON s.id = a.service_id
                 WHERE a.practitioner_id = ? AND a.scheduled_start >= ? AND a.scheduled_start < ? AND a.deleted_at IS NULL
                   AND a.status IN ('CONFIRME', 'DEPLACE', 'HONORE') ORDER BY a.scheduled_start LIMIT 30",
                [$userId, $dayStart, $dayEnd]
            ));
        }
        if ($auth->can('consultations.update')) {
            $data['my_open_consultations'] = $this->count(
                "SELECT COUNT(*) FROM consultations WHERE practitioner_id = ? AND status IN ('OUVERTE', 'EN_COURS') AND deleted_at IS NULL",
                [$userId]
            );
        }
        if ($auth->can('homecare.dispatch')) {
            $in = "'" . implode("', '", self::HOMECARE_OPEN) . "'";
            $data['homecare'] = [
                'by_status' => $this->byStatus('SELECT status, COUNT(*) AS n FROM homecare_requests WHERE status IN (' . $in . ') AND deleted_at IS NULL GROUP BY status'),
                'urgent_waiting' => $this->count("SELECT COUNT(*) FROM homecare_requests WHERE status IN ('NOUVELLE', 'EN_ATTENTE') AND urgency = 'URGENTE' AND deleted_at IS NULL"),
                'completed_today' => $this->count(
                    "SELECT COUNT(*) FROM homecare_requests WHERE status IN ('TERMINEE', 'FACTUREE') AND closed_at >= ? AND closed_at < ? AND deleted_at IS NULL",
                    [$dayStart, $dayEnd]
                ),
            ];
        }
        if ($auth->can('homecare.intervene')) {
            list($teamSql, $teamParams) = TeamService::teamIdsSql($userId);
            $data['my_team_visits'] = [
                'waiting' => $this->count("SELECT COUNT(*) FROM homecare_requests WHERE status = 'EN_ATTENTE' AND deleted_at IS NULL"),
                'in_progress' => $this->count(
                    "SELECT COUNT(*) FROM homecare_requests WHERE status IN ('PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS')
                     AND deleted_at IS NULL AND assigned_team_id IN (" . $teamSql . ')',
                    $teamParams
                ),
            ];
        }
        if ($auth->can('examinations.perform')) {
            $data['exam_queue'] = $this->count("SELECT COUNT(*) FROM examinations WHERE status IN ('PRESCRIT', 'EN_COURS') AND deleted_at IS NULL");
        }
        if ($auth->can('examinations.validate')) {
            $data['exams_to_validate'] = $this->count(
                "SELECT COUNT(*) FROM examinations e JOIN patients p ON p.id = e.patient_id
                 WHERE e.status = 'TERMINE' AND e.deleted_at IS NULL AND (e.prescribed_by = ? OR p.attending_physician_id = ?)",
                [$userId, $userId]
            );
        }
        if ($auth->can('invoices.read')) {
            $row = (array) $this->db->fetchOne(
                "SELECT COUNT(*) AS n, COALESCE(SUM(net_amount), 0) AS net, COALESCE(SUM(net_amount - declared_paid_amount), 0) AS outstanding
                 FROM invoices WHERE status = 'EMISE' AND issued_at >= ? AND deleted_at IS NULL",
                [$monthStart]
            );
            $data['billing_month'] = [
                'issued_count' => (int) $row['n'],
                'net_amount' => (int) $row['net'],
                'outstanding_amount' => (int) $row['outstanding'],
                'drafts' => $this->count("SELECT COUNT(*) FROM invoices WHERE status = 'BROUILLON' AND deleted_at IS NULL"),
                'visits_to_invoice' => $this->count("SELECT COUNT(*) FROM homecare_requests WHERE status = 'TERMINEE' AND deleted_at IS NULL"),
            ];
        }
        return $data;
    }

    private function count(string $sql, array $params = []): int
    {
        return (int) $this->db->fetchValue($sql, $params);
    }

    /**
     * @return array<string,int>
     */
    private function byStatus(string $sql, array $params = []): array
    {
        $result = [];
        foreach ($this->db->fetchAll($sql, $params) as $row) {
            $result[(string) $row['status']] = (int) $row['n'];
        }
        return $result;
    }
}
