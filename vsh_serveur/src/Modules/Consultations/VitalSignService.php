<?php

declare(strict_types=1);

namespace Vsh\Modules\Consultations;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;

/**
 * Constantes : une ligne par prise de mesure (ajout seulement, jamais modifiée), dans une consultation
 * ou en dehors (soins infirmiers, visite de suivi).
 *
 * Les bornes ci-dessous ne sont pas des normes cliniques : elles écartent les erreurs de saisie
 * physiquement impossibles (37,5 tapé 375). L'interprétation reste celle du professionnel.
 */
final class VitalSignService
{
    private const MEASURES = [
        'temperature_c' => 'nullable|numeric|min:25|max:45',
        'systolic_mmhg' => 'nullable|integer|min:40|max:300',
        'diastolic_mmhg' => 'nullable|integer|min:20|max:200',
        'pulse_bpm' => 'nullable|integer|min:20|max:250',
        'respiratory_rate' => 'nullable|integer|min:4|max:80',
        'spo2_percent' => 'nullable|integer|min:50|max:100',
        'weight_kg' => 'nullable|numeric|min:0.3|max:400',
        'height_cm' => 'nullable|numeric|min:20|max:250',
        'glycemia_g_l' => 'nullable|numeric|min:0.1|max:10',
    ];

    /** @var Database */
    private $db;

    /** @var ConsultationRepository */
    private $consultations;

    /** @var ConsultationService */
    private $consultationService;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        ConsultationRepository $consultations,
        ConsultationService $consultationService,
        PatientRepository $patients,
        PatientPolicy $policy,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->consultations = $consultations;
        $this->consultationService = $consultationService;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    /**
     * @param string|null $consultationUuid Consultation (ouverte) de rattachement
     * @param string|null $patientUuid      Patient, pour une prise de mesure hors consultation
     */
    public function record(?string $consultationUuid, ?string $patientUuid, array $input, Request $request, ?string $uuid = null): array
    {
        $auth = self::auth($request);
        if (!$auth->can('vitals.record')) {
            throw HttpException::forbidden();
        }
        $consultation = null;
        if ($consultationUuid !== null) {
            $consultation = $this->consultationService->findOrFail($consultationUuid);
            $this->consultationService->assertOpen($consultation);
            $patient = $this->consultationService->patientOf($consultation);
        } else {
            $patient = $patientUuid !== null ? $this->patients->findByUuid($patientUuid) : null;
            if ($patient === null) {
                throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
            }
        }
        if ($patient['status'] !== 'ACTIVE') {
            throw HttpException::conflict('Le dossier du patient doit être validé.', 'PATIENT_NOT_ACTIVE');
        }

        $data = $this->validator->validate($input, self::MEASURES + [
            'notes' => 'nullable|string|max:500',
            'recorded_at' => 'nullable|datetime',
        ]);
        if (array_filter(array_intersect_key($data, self::MEASURES), function ($value): bool {
            return $value !== null;
        }) === []) {
            throw new ValidationException(['measures' => ['Saisissez au moins une mesure.']]);
        }
        $recordedAt = $data['recorded_at'] ?? Clock::nowForDatabase();
        ConsultationService::assertNotFuture($recordedAt, 'recorded_at');
        unset($data['recorded_at']);

        return $this->db->transaction(function () use ($consultation, $patient, $data, $recordedAt, $auth, $request, $uuid): array {
            $uuid = $uuid ?? Uuid::v4();
            $id = $this->consultations->insert('vital_signs', $data + [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'consultation_id' => $consultation !== null ? (int) $consultation['id'] : null,
                'recorded_by' => $auth->userId(),
                'recorded_at' => $recordedAt,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
            ]);
            if ($consultation !== null) {
                $this->consultationService->touchInProgress($consultation, $auth);
            }
            $this->journal->record('vital_sign', $uuid, ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record('VITALS_RECORDED', $request, 'patient', (string) $patient['uuid'], null, [
                'measures' => array_keys(array_filter($data, function ($value): bool {
                    return $value !== null;
                })),
            ]);
            $row = (array) $this->db->fetchOne('SELECT * FROM vital_signs WHERE id = ?', [$id]);
            return self::present($row, (string) $patient['uuid'], $consultation !== null ? (string) $consultation['uuid'] : null);
        });
    }

    public function history(string $patientUuid, Request $request): array
    {
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null) {
            throw HttpException::notFound('Patient introuvable.');
        }
        $auth = self::auth($request);
        $this->policy->assertReadMedical($auth, $patient);
        $this->audit->record('MEDICAL_RECORD_VIEWED', $request, 'patient', $patientUuid, null, ['section' => 'vitals']);
        $rows = $this->consultations->vitalsForPatient((int) $patient['id'], 100);
        $consultations = $this->consultations->uuids('consultations', array_column($rows, 'consultation_id'));
        return array_map(function (array $row) use ($patientUuid, $consultations): array {
            return self::present($row, $patientUuid, $row['consultation_id'] !== null ? ($consultations[(int) $row['consultation_id']] ?? null) : null);
        }, $rows);
    }

    public static function present(array $row, ?string $patientUuid, ?string $consultationUuid): array
    {
        $item = ['id' => (string) $row['uuid'], 'patient_id' => $patientUuid, 'consultation_id' => $consultationUuid];
        foreach (self::MEASURES as $field => $rules) {
            $value = $row[$field];
            $item[$field] = $value === null ? null : (strpos($rules, 'integer') !== false ? (int) $value : (float) $value);
        }
        // Indice calculé (poids / taille²) : aide à la lecture, sans interprétation.
        $item['bmi'] = ($item['weight_kg'] !== null && $item['height_cm'] !== null)
            ? round($item['weight_kg'] / (($item['height_cm'] / 100) ** 2), 1)
            : null;
        $item['notes'] = $row['notes'];
        $item['recorded_at'] = Clock::toIso((string) $row['recorded_at']);
        $item['version'] = (int) $row['version'];
        return $item;
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
