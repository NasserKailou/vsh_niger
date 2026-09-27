<?php

declare(strict_types=1);

namespace Vsh\Modules\Notifications;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Notifications : reçues par l'appareil de leur destinataire ; seule la lecture (read_at) peut être
 * renvoyée, y compris hors ligne.
 */
final class NotificationSyncHandler implements SyncEntityHandler
{
    /** @var Database */
    private $db;

    /** @var NotificationService */
    private $notifications;

    public function __construct(Database $db, NotificationService $notifications)
    {
        $this->db = $db;
        $this->notifications = $notifications;
    }

    public function entity(): string
    {
        return 'notification';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'UPDATE';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM notifications WHERE uuid = ? AND user_id = ?', [$uuid, self::auth($request)->userId()]);
        if ($row === null) {
            return null;
        }
        // Les notifications ne sont jamais modifiées sur le serveur, hormis leur lecture.
        return NotificationService::present($row) + ['version' => $row['read_at'] === null ? 1 : 2];
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        if (($fields['read_at'] ?? null) !== null) {
            $this->notifications->markRead(self::auth($request)->userId(), $uuid);
        }
        $current = $this->current($uuid, $request);
        if ($current === null) {
            throw HttpException::notFound('Notification introuvable.');
        }
        return $current;
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        throw new \LogicException('Les notifications sont créées par le serveur.');
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Les notifications ne se suppriment pas.');
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
