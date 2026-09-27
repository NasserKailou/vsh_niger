<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients\Sync;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Patients\PatientService;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Identité du patient. Création hors ligne par le personnel (numéro de dossier attribué à la réception
 * par le serveur, doublon signalé à l'accueil) et modification de l'identité.
 */
final class PatientSyncHandler implements SyncEntityHandler, PatientScopedEntity
{
    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM patients WHERE id = ?';
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
        return 'patient';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['CREATE', 'UPDATE'], true);
    }

    public function current(string $uuid, Request $request): ?array
    {
        $patient = $this->patients->findByUuid($uuid);
        if ($patient === null) {
            return null;
        }
        $auth = self::auth($request);
        $visible = $auth->isPatient() ? $this->policy->owns($auth, $patient) : $auth->can('patients.read');
        return $visible ? $this->service->present($patient) : null;
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if (!self::auth($request)->can('patients.create')) {
            throw HttpException::forbidden();
        }
        if ($this->patients->uuidExists($uuid)) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Ce dossier existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        $this->service->createFromSync(['id' => $uuid] + $payload, $request);
        return (array) $this->current($uuid, $request);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        if (!self::auth($request)->can('patients.update')) {
            throw HttpException::forbidden();
        }
        $this->service->update($uuid, $fields, $request);
        return (array) $this->current($uuid, $request);
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Un dossier patient ne se supprime pas.');
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
