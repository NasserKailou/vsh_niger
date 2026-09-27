<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients\Sync;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Patients\PatientService;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Profil médical (groupe sanguin, observations) : un seul par patient.
 * Si deux appareils le créent hors ligne, le second met à jour le profil existant.
 */
final class MedicalProfileSyncHandler implements SyncEntityHandler, PatientScopedEntity
{
    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM patient_medical_profiles WHERE patient_id = ?';
    }

    /** @var PatientRepository */
    private $patients;

    /** @var PatientService */
    private $service;

    /** @var PatientPolicy */
    private $policy;

    public function __construct(PatientRepository $patients, PatientService $service, PatientPolicy $policy)
    {
        $this->patients = $patients;
        $this->service = $service;
        $this->policy = $policy;
    }

    public function entity(): string
    {
        return 'patient_medical_profile';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['CREATE', 'UPDATE'], true);
    }

    public function current(string $uuid, Request $request): ?array
    {
        $profile = $this->patients->medicalProfileByUuid($uuid);
        $patient = $profile !== null ? $this->patients->findById((int) $profile['patient_id']) : null;
        if ($profile === null || $patient === null || !$this->policy->canReadMedical(self::auth($request), $patient)) {
            return null;
        }
        return (array) $this->service->presentMedicalProfile($profile) + ['patient_id' => (string) $patient['uuid']];
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        $patientUuid = $payload['patient_id'] ?? null;
        $patient = is_string($patientUuid) ? $this->patients->findByUuid($patientUuid) : null;
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        unset($payload['patient_id']);
        $this->service->updateMedicalProfile((string) $patient['uuid'], $payload, $request, $uuid);
        $profile = (array) $this->patients->medicalProfile((int) $patient['id']);
        return (array) $this->current((string) $profile['uuid'], $request);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        $profile = $this->patients->medicalProfileByUuid($uuid);
        $patient = $profile !== null ? $this->patients->findById((int) $profile['patient_id']) : null;
        if ($profile === null || $patient === null) {
            throw HttpException::notFound('Profil médical introuvable.');
        }
        unset($fields['patient_id']);
        $this->service->updateMedicalProfile((string) $patient['uuid'], $fields, $request);
        return (array) $this->current($uuid, $request);
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Le profil médical ne se supprime pas.');
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
