<?php

declare(strict_types=1);

namespace Vsh\Modules\Consultations\Sync;

use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Consultations\ConsultationRepository;
use Vsh\Modules\Consultations\ConsultationService;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncActionHandler;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Consultation : ouverture et saisie hors ligne, clôture et annulation par actions (« close », « cancel »).
 * L'état transmis ne contient pas les éléments (constantes, diagnostics, notes), synchronisés séparément.
 */
final class ConsultationSyncHandler implements SyncEntityHandler, SyncActionHandler, PatientScopedEntity
{
    /** @var ConsultationRepository */
    private $consultations;

    /** @var ConsultationService */
    private $service;

    public function __construct(ConsultationRepository $consultations, ConsultationService $service)
    {
        $this->consultations = $consultations;
        $this->service = $service;
    }

    public function entity(): string
    {
        return 'consultation';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['CREATE', 'UPDATE'], true);
    }

    public function actions(): array
    {
        return ['close', 'cancel'];
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM consultations WHERE patient_id = ? AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $consultation = $this->consultations->findByUuid($uuid);
        $auth = self::auth($request);
        if ($consultation === null || !$auth->can('consultations.read')) {
            return null;
        }
        $item = $this->service->presentMany([$consultation])[0];
        if ($this->service->canReadClinical($auth, $consultation)) {
            $item += $this->service->clinicalFields($consultation);
        }
        return $item;
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if (!self::auth($request)->can('consultations.create')) {
            throw HttpException::forbidden();
        }
        if ($this->consultations->uuidExists('consultations', $uuid)) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Cette consultation existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        $this->service->create($payload, $request, $uuid);
        return (array) $this->current($uuid, $request);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        if (!self::auth($request)->can('consultations.update')) {
            throw HttpException::forbidden();
        }
        $this->service->update($uuid, $fields, $request);
        return (array) $this->current($uuid, $request);
    }

    public function action(string $uuid, string $action, array $payload, Request $request): array
    {
        $auth = self::auth($request);
        if ($action === 'close') {
            if (!$auth->can('consultations.close')) {
                throw HttpException::forbidden();
            }
            $this->service->close($uuid, $request);
        } else {
            if (!$auth->can('consultations.update')) {
                throw HttpException::forbidden();
            }
            $this->service->cancel($uuid, $payload, $request);
        }
        return (array) $this->current($uuid, $request);
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Une consultation ne se supprime pas : elle s\'annule.');
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
