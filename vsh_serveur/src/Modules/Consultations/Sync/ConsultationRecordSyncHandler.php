<?php

declare(strict_types=1);

namespace Vsh\Modules\Consultations\Sync;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Consultations\ConsultationRepository;
use Vsh\Modules\Consultations\ConsultationService;
use Vsh\Modules\Consultations\VitalSignService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Éléments cliniques ajoutés à un dossier (ajout seulement, donc jamais en conflit) :
 * « vital_sign » (constantes), « consultation_diagnosis » (retrait possible tant que la consultation
 * est ouverte) et « consultation_note » (note ou addendum).
 */
final class ConsultationRecordSyncHandler implements SyncEntityHandler, PatientScopedEntity
{
    private const DEFINITIONS = [
        'vital_sign' => ['table' => 'vital_signs', 'operations' => ['CREATE']],
        'consultation_diagnosis' => ['table' => 'consultation_diagnoses', 'operations' => ['CREATE', 'DELETE']],
        'consultation_note' => ['table' => 'consultation_notes', 'operations' => ['CREATE']],
    ];

    /** @var string */
    private $entity;

    /** @var ConsultationRepository */
    private $consultations;

    /** @var ConsultationService */
    private $service;

    /** @var VitalSignService */
    private $vitals;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    public function __construct(
        string $entity,
        ConsultationRepository $consultations,
        ConsultationService $service,
        VitalSignService $vitals,
        PatientRepository $patients,
        PatientPolicy $policy
    ) {
        if (!isset(self::DEFINITIONS[$entity])) {
            throw new \LogicException(sprintf('Élément clinique inconnu : %s', $entity));
        }
        $this->entity = $entity;
        $this->consultations = $consultations;
        $this->service = $service;
        $this->vitals = $vitals;
        $this->patients = $patients;
        $this->policy = $policy;
    }

    /**
     * @return string[]
     */
    public static function entities(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, self::DEFINITIONS[$this->entity]['operations'], true);
    }

    public function snapshotSql(): string
    {
        if ($this->entity === 'vital_sign') {
            return 'SELECT uuid FROM vital_signs WHERE patient_id = ? AND deleted_at IS NULL';
        }
        return 'SELECT r.uuid FROM `' . self::DEFINITIONS[$this->entity]['table'] . '` r
                JOIN consultations c ON c.id = r.consultation_id
                WHERE c.patient_id = ? AND r.deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $row = $this->consultations->findRecord(self::DEFINITIONS[$this->entity]['table'], $uuid);
        if ($row === null) {
            return null;
        }
        $consultation = isset($row['consultation_id']) && $row['consultation_id'] !== null
            ? $this->consultations->findById((int) $row['consultation_id'])
            : null;
        $patientId = $this->entity === 'vital_sign' ? (int) $row['patient_id'] : (int) ($consultation['patient_id'] ?? 0);
        $patient = $this->patients->findById($patientId);
        if ($patient === null || !$this->policy->canReadMedical(self::auth($request), $patient)) {
            return null;
        }
        $consultationUuid = $consultation !== null ? (string) $consultation['uuid'] : null;
        switch ($this->entity) {
            case 'vital_sign':
                return VitalSignService::present($row, (string) $patient['uuid'], $consultationUuid);
            case 'consultation_diagnosis':
                return $this->service->presentDiagnosis($row, (string) $consultationUuid) + ['patient_id' => (string) $patient['uuid']];
            default:
                return $this->service->presentNote($row, (string) $consultationUuid) + ['patient_id' => (string) $patient['uuid']];
        }
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if ($this->consultations->uuidExists(self::DEFINITIONS[$this->entity]['table'], $uuid)) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Cet élément existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        $consultationUuid = isset($payload['consultation_id']) && is_string($payload['consultation_id']) ? $payload['consultation_id'] : null;
        unset($payload['consultation_id']);

        if ($this->entity === 'vital_sign') {
            $patientUuid = isset($payload['patient_id']) && is_string($payload['patient_id']) ? $payload['patient_id'] : null;
            unset($payload['patient_id']);
            return $this->vitals->record($consultationUuid, $patientUuid, $payload, $request, $uuid);
        }
        if ($consultationUuid === null) {
            throw new ValidationException(['consultation_id' => ['Consultation obligatoire.']]);
        }
        unset($payload['patient_id']);
        $auth = self::auth($request);
        if ($this->entity === 'consultation_diagnosis') {
            if (!$auth->can('diagnoses.write')) {
                throw HttpException::forbidden();
            }
            $this->service->addDiagnosis($consultationUuid, $payload, $request, $uuid);
        } else {
            if (!$auth->can('consultations.update')) {
                throw HttpException::forbidden();
            }
            $this->service->addNote($consultationUuid, $payload, $request, $uuid);
        }
        return (array) $this->current($uuid, $request);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        throw new \LogicException('Les éléments cliniques ne se modifient pas.');
    }

    public function delete(string $uuid, Request $request): void
    {
        if (!self::auth($request)->can('diagnoses.write')) {
            throw HttpException::forbidden();
        }
        $this->service->deleteDiagnosis($uuid, $request);
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
