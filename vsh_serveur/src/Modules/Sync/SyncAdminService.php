<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Security\TokenService;
use Vsh\Core\Support\Clock;
use Vsh\Core\Validation\Validator;

/**
 * Supervision de la synchronisation par l'administration (permission sync.supervise).
 */
final class SyncAdminService
{
    /** @var Database */
    private $db;

    /** @var TokenService */
    private $tokens;

    /** @var Validator */
    private $validator;

    /** @var AuditLogger */
    private $audit;

    public function __construct(Database $db, TokenService $tokens, Validator $validator, AuditLogger $audit)
    {
        $this->db = $db;
        $this->tokens = $tokens;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function devices(Pagination $pagination): array
    {
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM devices');
        $rows = $this->db->fetchAll(
            "SELECT d.*, u.uuid AS user_uuid, u.first_name, u.last_name,
                    (SELECT COUNT(*) FROM sync_operations so WHERE so.device_id = d.id AND so.status = 'REJECTED') AS rejected_operations,
                    (SELECT COUNT(*) FROM sync_conflicts sc JOIN sync_operations so ON so.id = sc.sync_operation_id
                      WHERE so.device_id = d.id AND sc.status = 'OUVERT') AS open_conflicts
             FROM devices d JOIN users u ON u.id = d.user_id
             ORDER BY d.revoked_at IS NULL DESC, d.last_sync_at DESC, d.id DESC
             LIMIT ? OFFSET ?",
            [$pagination->perPage(), $pagination->offset()]
        );
        return [array_map(function (array $row): array {
            return [
                'id' => (string) $row['uuid'],
                'platform' => (string) $row['platform'],
                'device_name' => $row['device_name'],
                'app_version' => $row['app_version'],
                'user' => ['id' => (string) $row['user_uuid'], 'name' => $row['first_name'] . ' ' . $row['last_name']],
                'last_seen_at' => Clock::toIso($row['last_seen_at']),
                'last_sync_at' => Clock::toIso($row['last_sync_at']),
                'revoked_at' => Clock::toIso($row['revoked_at']),
                'rejected_operations' => (int) $row['rejected_operations'],
                'open_conflicts' => (int) $row['open_conflicts'],
            ];
        }, $rows), $total];
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function operations(array $query, Pagination $pagination): array
    {
        $filters = $this->validator->validate($query, [
            'status' => 'nullable|in:APPLIED,CONFLICT,REJECTED',
            'device_id' => 'nullable|uuid',
        ]);
        $where = ['1 = 1'];
        $params = [];
        if (isset($filters['status'])) {
            $where[] = 'so.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['device_id'])) {
            $where[] = 'd.uuid = ?';
            $params[] = $filters['device_id'];
        }
        $sql = ' FROM sync_operations so JOIN devices d ON d.id = so.device_id JOIN users u ON u.id = so.user_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT so.*, d.uuid AS device_uuid, u.uuid AS user_uuid, u.first_name, u.last_name' . $sql
            . ' ORDER BY so.received_at DESC, so.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row): array {
            $result = json_decode((string) $row['result'], true);
            return [
                'op_id' => (string) $row['op_id'],
                'entity' => (string) $row['entity'],
                'entity_id' => (string) $row['entity_uuid'],
                'operation' => (string) $row['operation'],
                'status' => (string) $row['status'],
                'error' => is_array($result) ? ($result['error'] ?? null) : null,
                'device_id' => (string) $row['device_uuid'],
                'user' => ['id' => (string) $row['user_uuid'], 'name' => $row['first_name'] . ' ' . $row['last_name']],
                'client_created_at' => Clock::toIso($row['client_created_at']),
                'received_at' => Clock::toIso((string) $row['received_at']),
            ];
        }, $rows), $total];
    }

    /**
     * Appareil perdu ou volé : sessions fermées immédiatement, reconnexion impossible depuis cet appareil.
     */
    public function revokeDevice(string $uuid, Request $request, AuthContext $auth): void
    {
        $device = $this->db->fetchOne('SELECT id, uuid, revoked_at FROM devices WHERE uuid = ?', [$uuid]);
        if ($device === null) {
            throw HttpException::notFound('Appareil introuvable.');
        }
        if ($device['revoked_at'] !== null) {
            return;
        }
        $this->db->transaction(function () use ($device, $request, $auth): void {
            $now = Clock::nowForDatabase();
            $this->db->update('devices', ['revoked_at' => $now, 'revoked_by' => $auth->userId(), 'updated_at' => $now], 'id = ?', [(int) $device['id']]);
            $this->tokens->revokeDevice((int) $device['id']);
            $this->audit->record('DEVICE_REVOKED_BY_ADMIN', $request, 'device', (string) $device['uuid']);
        });
    }
}
