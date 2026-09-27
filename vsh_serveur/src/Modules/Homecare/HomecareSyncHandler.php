<?php

declare(strict_types=1);

namespace Vsh\Modules\Homecare;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncActionHandler;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Visites à domicile : demande créée hors ligne et transitions faites sur le terrain sans réseau.
 * Chaque action repasse par la machine à états du serveur ; `payload.at` porte l'heure réelle de l'action.
 */
final class HomecareSyncHandler implements SyncEntityHandler, SyncActionHandler, PatientScopedEntity
{
    /** @var Database */
    private $db;

    /** @var HomecareService */
    private $service;

    public function __construct(Database $db, HomecareService $service)
    {
        $this->db = $db;
        $this->service = $service;
    }

    public function entity(): string
    {
        return 'homecare_request';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'CREATE';
    }

    public function actions(): array
    {
        return ['approve', 'accept', 'assign', 'release', 'depart', 'arrive', 'start', 'complete', 'fail', 'cancel', 'track'];
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM homecare_requests WHERE patient_id = ? AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $auth = self::auth($request);
        $row = $this->db->fetchOne('SELECT * FROM homecare_requests WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            return null;
        }
        $level = $this->service->access($auth, $row);
        return $level === null ? null : $this->service->present($row, $level, $level === 'full');
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if ($this->db->fetchValue('SELECT id FROM homecare_requests WHERE uuid = ?', [$uuid]) !== null) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Cette demande existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        return $this->service->create($payload, $request, $uuid);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        throw new \LogicException('Une visite à domicile évolue par actions, pas par modification directe.');
    }

    public function action(string $uuid, string $action, array $payload, Request $request): array
    {
        switch ($action) {
            case 'approve':
                return $this->service->approve($uuid, $request);
            case 'accept':
                return $this->service->accept($uuid, $payload, $request);
            case 'assign':
                return $this->service->assign($uuid, $payload, $request);
            case 'release':
                return $this->service->release($uuid, $payload, $request);
            case 'cancel':
                return $this->service->cancel($uuid, $payload, $request);
            case 'track':
                $this->service->track($uuid, $payload, $request);
                return (array) $this->current($uuid, $request);
            default:
                return $this->service->fieldAction($uuid, $action, $payload, $request);
        }
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Une visite à domicile ne se supprime pas : elle s\'annule.');
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
