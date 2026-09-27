<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Database;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Patients\PatientService;
use Vsh\Modules\Settings\SettingsService;
use Vsh\Modules\Teams\TeamService;

/**
 * Périmètre de synchronisation (D-007, minimisation) : un appareil ne reçoit pas toute la base.
 *
 * - Compte patient : ses propres dossiers.
 * - Personnel : dossiers dont il est médecin traitant, dossiers qu'il a créés ou complétés durant les
 *   N derniers mois (sync.offline_scope_months), et dossiers qu'il a « épinglés » pour le hors ligne.
 *   Technicien : patients ayant un examen en attente. Équipes mobiles : patients de leurs visites en cours.
 */
final class SyncScope
{
    /** Tables dont les saisies récentes de l'utilisateur font entrer le patient dans son périmètre. */
    private const CONTRIBUTION_TABLES = [
        'patient_contacts',
        'patient_addresses',
        'allergies',
        'medical_history',
        'patient_current_treatments',
        'patient_medical_profiles',
        'consultations',
        'vital_signs',
        'treatments',
        'examinations',
        'prescriptions',
        'homecare_requests',
    ];

    /** @var Database */
    private $db;

    /** @var SettingsService */
    private $settings;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientService */
    private $patientService;

    /** @var AuditLogger */
    private $audit;

    /** @var PatientSnapshot */
    private $snapshot;

    public function __construct(
        Database $db,
        SettingsService $settings,
        PatientRepository $patients,
        PatientService $patientService,
        AuditLogger $audit,
        PatientSnapshot $snapshot
    ) {
        $this->db = $db;
        $this->settings = $settings;
        $this->patients = $patients;
        $this->patientService = $patientService;
        $this->audit = $audit;
        $this->snapshot = $snapshot;
    }

    /**
     * Sous-requête SQL renvoyant les identifiants des patients du périmètre, et ses paramètres.
     *
     * @return array{0: string, 1: array}
     */
    public function patientIdsSql(AuthContext $auth): array
    {
        $userId = $auth->userId();
        if ($auth->isPatient()) {
            return ['SELECT id FROM patients WHERE user_id = ?', [$userId]];
        }
        $months = max(1, (int) $this->settings->get('sync.offline_scope_months', 6));
        $since = Clock::now()->modify('-' . $months . ' months')->format('Y-m-d H:i:s');

        $parts = [
            'SELECT id FROM patients WHERE attending_physician_id = ?',
            'SELECT id FROM patients WHERE created_by = ? AND created_at >= ?',
            'SELECT patient_id FROM sync_patient_subscriptions WHERE user_id = ?',
            'SELECT patient_id FROM consultations WHERE practitioner_id = ? AND started_at >= ?',
            'SELECT patient_id FROM treatments WHERE performed_by = ? AND performed_at >= ?',
            'SELECT patient_id FROM examinations WHERE technician_id = ? AND performed_at >= ?',
        ];
        $params = [$userId, $userId, $since, $userId, $userId, $since, $userId, $since, $userId, $since];
        if ($auth->can('homecare.intervene')) {
            // Équipes mobiles : dossiers des visites en cours affectées à l'une de leurs équipes.
            list($teamSql, $teamParams) = TeamService::teamIdsSql($userId);
            $parts[] = "SELECT patient_id FROM homecare_requests WHERE status IN ('PRISE_EN_CHARGE', 'EN_ROUTE', 'SUR_PLACE', 'EN_COURS')
                        AND deleted_at IS NULL AND assigned_team_id IN (" . $teamSql . ')';
            $params = array_merge($params, $teamParams);
        }
        if ($auth->can('examinations.perform')) {
            // File du technicien : patients ayant un examen à réaliser.
            $parts[] = "SELECT patient_id FROM examinations WHERE status IN ('PRESCRIT', 'EN_COURS') AND deleted_at IS NULL";
        }
        foreach (self::CONTRIBUTION_TABLES as $table) {
            $parts[] = 'SELECT patient_id FROM `' . $table . '` WHERE created_by = ? AND created_at >= ?';
            array_push($params, $userId, $since);
        }
        return [implode(' UNION ', $parts), $params];
    }

    /**
     * Rend un dossier disponible hors ligne sur les appareils de l'utilisateur.
     */
    public function pin(string $patientUuid, Request $request, AuthContext $auth): void
    {
        $patient = $this->patientService->findOrFail($patientUuid);
        $this->db->transaction(function () use ($patient, $request, $auth): void {
            $inserted = $this->db->execute(
                'INSERT IGNORE INTO sync_patient_subscriptions (user_id, patient_id, created_at) VALUES (?, ?, ?)',
                [$auth->userId(), (int) $patient['id'], Clock::nowForDatabase()]
            );
            if ($inserted > 0) {
                // Les changements anciens du dossier ont un numéro inférieur au curseur des appareils :
                // le dossier complet est réinscrit dans le journal pour qu'ils le reçoivent.
                $this->snapshot->record((int) $patient['id']);
                $this->audit->record('SYNC_PATIENT_PINNED', $request, 'patient', (string) $patient['uuid']);
            }
        });
    }

    public function unpin(string $patientUuid, Request $request, AuthContext $auth): void
    {
        $patient = $this->patientService->findOrFail($patientUuid);
        $removed = $this->db->execute(
            'DELETE FROM sync_patient_subscriptions WHERE user_id = ? AND patient_id = ?',
            [$auth->userId(), (int) $patient['id']]
        );
        if ($removed > 0) {
            $this->audit->record('SYNC_PATIENT_UNPINNED', $request, 'patient', (string) $patient['uuid']);
        }
    }

    public function pinned(AuthContext $auth): array
    {
        $rows = $this->db->fetchAll(
            'SELECT p.* FROM sync_patient_subscriptions s JOIN patients p ON p.id = s.patient_id
             WHERE s.user_id = ? AND p.deleted_at IS NULL ORDER BY p.last_name, p.first_name',
            [$auth->userId()]
        );
        return $this->patientService->presentMany($rows);
    }
}
