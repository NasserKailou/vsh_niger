<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients\Sync;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Patients\PatientChildService;
use Vsh\Modules\Patients\PatientChildren;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Contacts, adresses, allergies, antécédents et traitements en cours (une instance par sous-ressource).
 * Les données médicales ne sont transmises qu'aux utilisateurs autorisés à les lire.
 */
final class PatientChildSyncHandler implements SyncEntityHandler, PatientScopedEntity
{
    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM `' . $this->definition['table'] . '` WHERE patient_id = ? AND deleted_at IS NULL';
    }

    /** @var string */
    private $name;

    /** @var array */
    private $definition;

    /** @var PatientChildService */
    private $children;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    public function __construct(string $name, PatientChildService $children, PatientRepository $patients, PatientPolicy $policy)
    {
        $this->name = $name;
        $this->definition = PatientChildren::get($name);
        $this->children = $children;
        $this->patients = $patients;
        $this->policy = $policy;
    }

    public function entity(): string
    {
        return (string) $this->definition['entity'];
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['CREATE', 'UPDATE', 'DELETE'], true);
    }

    public function current(string $uuid, Request $request): ?array
    {
        $row = $this->children->findRow($this->name, $uuid);
        if ($row === null) {
            return null;
        }
        $patient = $this->patients->findById((int) $row['patient_id']);
        if ($patient === null || !$this->visible(self::auth($request), $patient)) {
            return null;
        }
        return $this->children->presentRow($this->name, $row) + ['patient_id' => (string) $patient['uuid']];
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        $patientUuid = $payload['patient_id'] ?? null;
        $patient = is_string($patientUuid) ? $this->patients->findByUuid($patientUuid) : null;
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        if ($this->children->uuidExists($this->name, $uuid)) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Cet élément existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        unset($payload['patient_id']);
        $item = $this->children->create($this->name, $patient, $payload, $request, $uuid);
        return $item + ['patient_id' => (string) $patient['uuid']];
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        list($row, $patient) = $this->findOrFail($uuid);
        unset($fields['patient_id']);
        return $this->children->update($this->name, $patient, (string) $row['uuid'], $fields, $request)
            + ['patient_id' => (string) $patient['uuid']];
    }

    public function delete(string $uuid, Request $request): void
    {
        $row = $this->children->findRow($this->name, $uuid);
        if ($row === null) {
            return;
        }
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $this->children->delete($this->name, $patient, $uuid, $request);
    }

    private function visible(AuthContext $auth, array $patient): bool
    {
        if ($this->definition['medical']) {
            return $this->policy->canReadMedical($auth, $patient);
        }
        return $auth->isPatient() ? $this->policy->owns($auth, $patient) : $auth->can('patients.read');
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function findOrFail(string $uuid): array
    {
        $row = $this->children->findRow($this->name, $uuid);
        $patient = $row !== null ? $this->patients->findById((int) $row['patient_id']) : null;
        if ($row === null || $patient === null) {
            throw HttpException::notFound((string) $this->definition['not_found']);
        }
        return [$row, $patient];
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
