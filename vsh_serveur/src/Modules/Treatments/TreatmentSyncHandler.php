<?php

declare(strict_types=1);

namespace Vsh\Modules\Treatments;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncActionHandler;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Soins : création (programmé ou réalisé), modification, et actions « perform » / « cancel » hors ligne.
 */
final class TreatmentSyncHandler implements SyncEntityHandler, SyncActionHandler, PatientScopedEntity
{
    /** @var Database */
    private $db;

    /** @var TreatmentService */
    private $service;

    public function __construct(Database $db, TreatmentService $service)
    {
        $this->db = $db;
        $this->service = $service;
    }

    public function entity(): string
    {
        return 'treatment';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['CREATE', 'UPDATE'], true);
    }

    public function actions(): array
    {
        return ['perform', 'cancel'];
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM treatments WHERE patient_id = ? AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $auth = self::auth($request);
        $row = $this->db->fetchOne('SELECT * FROM treatments WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null || !$auth->can('treatments.read')) {
            return null;
        }
        return $this->service->present($row, $auth);
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if ($this->db->fetchValue('SELECT id FROM treatments WHERE uuid = ?', [$uuid]) !== null) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Ce soin existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        return $this->service->create($payload, $request, $uuid);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        return $this->service->update($uuid, $fields, $request);
    }

    public function action(string $uuid, string $action, array $payload, Request $request): array
    {
        return $action === 'perform'
            ? $this->service->perform($uuid, $payload, $request)
            : $this->service->cancel($uuid, $payload, $request);
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Un soin ne se supprime pas : il s\'annule.');
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
