<?php

declare(strict_types=1);

namespace Vsh\Modules\Notifications;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Validation\Validator;

/**
 * Jetons push des appareils (D-011). Un jeton est toujours rattaché à l'appareil de la session qui
 * l'enregistre : il cesse de servir à la déconnexion, à la révocation de l'appareil ou quand le
 * service le déclare invalide (application désinstallée).
 */
final class PushTokenService
{
    /** @var Database */
    private $db;

    /** @var Validator */
    private $validator;

    /** @var AuditLogger */
    private $audit;

    public function __construct(Database $db, Validator $validator, AuditLogger $audit)
    {
        $this->db = $db;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function register(array $input, Request $request, AuthContext $auth): void
    {
        $data = $this->validator->validate($input, [
            'token' => 'required|raw|string|min:20|max:512|regex:/^[A-Za-z0-9:_.\-]+$/',
            'provider' => 'nullable|in:FCM',
        ]);
        $deviceId = $auth->deviceId();
        if ($deviceId === null) {
            throw HttpException::conflict(
                'Le push nécessite une session ouverte depuis l\'application mobile (appareil enregistré).',
                'DEVICE_REQUIRED'
            );
        }
        $provider = $data['provider'] ?? 'FCM';
        $token = (string) $data['token'];

        $this->db->transaction(function () use ($auth, $deviceId, $provider, $token, $request): void {
            // Un même téléphone peut changer d'utilisateur : son jeton ne doit plus servir à l'ancien.
            $this->db->execute('DELETE FROM push_tokens WHERE token = ? AND device_id <> ?', [$token, $deviceId]);
            $existing = $this->db->fetchOne('SELECT id FROM push_tokens WHERE device_id = ? AND provider = ?', [$deviceId, $provider]);
            $now = Clock::nowForDatabase();
            if ($existing === null) {
                $this->db->insert('push_tokens', [
                    'user_id' => $auth->userId(),
                    'device_id' => $deviceId,
                    'provider' => $provider,
                    'token' => $token,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->audit->record('PUSH_TOKEN_REGISTERED', $request, 'user', $auth->userUuid());
            } else {
                $this->db->update('push_tokens', ['user_id' => $auth->userId(), 'token' => $token, 'updated_at' => $now], 'id = ?', [(int) $existing['id']]);
            }
        });
    }

    /**
     * Désactivation du push pour un appareil (réglage de l'application, déconnexion).
     */
    public function unregisterDevice(?int $deviceId): int
    {
        if ($deviceId === null) {
            return 0;
        }
        return $this->db->execute('DELETE FROM push_tokens WHERE device_id = ?', [$deviceId]);
    }

    public static function hasActiveToken(Database $db, int $userId): bool
    {
        return $db->fetchValue(
            'SELECT 1 FROM push_tokens t JOIN devices d ON d.id = t.device_id
             WHERE t.user_id = ? AND d.user_id = t.user_id AND d.revoked_at IS NULL LIMIT 1',
            [$userId]
        ) !== null;
    }

    /**
     * @return array<int,array{id: int, token: string}>
     */
    public static function activeTokens(Database $db, int $userId): array
    {
        return array_map(function (array $row): array {
            return ['id' => (int) $row['id'], 'token' => (string) $row['token']];
        }, $db->fetchAll(
            'SELECT t.id, t.token FROM push_tokens t JOIN devices d ON d.id = t.device_id
             WHERE t.user_id = ? AND d.user_id = t.user_id AND d.revoked_at IS NULL ORDER BY t.id',
            [$userId]
        ));
    }
}
