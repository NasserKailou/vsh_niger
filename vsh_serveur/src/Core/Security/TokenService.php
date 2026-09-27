<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Config;
use Vsh\Core\Database;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;

/**
 * Jetons opaques. Seule l'empreinte SHA-256 est stockée en base.
 *
 * - Jeton d'accès : courte durée (ACCESS_TOKEN_TTL), présenté dans « Authorization: Bearer ».
 * - Refresh token : longue durée, à usage unique et rotatif. Tous les refresh tokens d'une même
 *   connexion forment une « famille ». Présenter un refresh token déjà remplacé est traité comme un vol :
 *   la famille entière (et ses jetons d'accès) est révoquée.
 * - Exception : si la réponse d'un renouvellement a été perdue (coupure réseau), l'application présente
 *   à nouveau l'ancien jeton. Dans un court délai de grâce, et si le jeton émis entre-temps n'a jamais
 *   servi, ce dernier est annulé et une nouvelle paire est émise, sans déconnecter l'utilisateur.
 */
final class TokenService
{
    public const RESULT_OK = 'OK';
    public const RESULT_INVALID = 'INVALID';
    public const RESULT_EXPIRED = 'EXPIRED';
    public const RESULT_REVOKED = 'REVOKED';
    public const RESULT_REUSED = 'REUSED';

    /** @var Database */
    private $db;

    /** @var Config */
    private $config;

    public function __construct(Database $db, Config $config)
    {
        $this->db = $db;
        $this->config = $config;
    }

    /**
     * Ouvre une nouvelle session (nouvelle famille).
     */
    public function issue(int $userId, ?int $deviceId, string $channel): array
    {
        return $this->db->transaction(function () use ($userId, $deviceId, $channel): array {
            list($tokens) = $this->createPair($userId, $deviceId, $channel, Uuid::v4());
            return $tokens;
        });
    }

    /**
     * @return array{result: string, user_id: ?int, device_id: ?int, family_id: ?string, tokens: ?array}
     */
    public function rotate(string $refreshToken): array
    {
        $hash = hash('sha256', $refreshToken);

        return $this->db->transaction(function () use ($hash): array {
            $row = $this->db->fetchOne('SELECT * FROM refresh_tokens WHERE token_hash = ? FOR UPDATE', [$hash]);
            if ($row === null) {
                return self::outcome(self::RESULT_INVALID);
            }
            $userId = (int) $row['user_id'];
            $deviceId = $row['device_id'] !== null ? (int) $row['device_id'] : null;
            $familyId = (string) $row['family_id'];
            $channel = $deviceId !== null ? 'MOBILE' : 'WEB';
            $now = Clock::nowForDatabase();

            if ($row['revoked_at'] !== null) {
                if ($row['replaced_by_id'] === null) {
                    return self::outcome(self::RESULT_REVOKED, $userId, $deviceId, $familyId);
                }
                $child = $this->db->fetchOne(
                    'SELECT id, revoked_at, replaced_by_id FROM refresh_tokens WHERE id = ? FOR UPDATE',
                    [(int) $row['replaced_by_id']]
                );
                $childUnused = $child !== null && $child['revoked_at'] === null && $child['replaced_by_id'] === null;
                if ($childUnused && $this->withinGracePeriod((string) $row['revoked_at'])) {
                    $this->revokeRefreshToken((int) $child['id'], $now);
                    list($tokens, $newId) = $this->createPair($userId, $deviceId, $channel, $familyId);
                    $this->db->update('refresh_tokens', ['replaced_by_id' => $newId], 'id = ?', [(int) $row['id']]);
                    return self::outcome(self::RESULT_OK, $userId, $deviceId, $familyId, $tokens);
                }
                $this->revokeFamily($familyId);
                return self::outcome(self::RESULT_REUSED, $userId, $deviceId, $familyId);
            }

            if ((string) $row['expires_at'] <= $now) {
                return self::outcome(self::RESULT_EXPIRED, $userId, $deviceId, $familyId);
            }

            $this->db->update('refresh_tokens', ['revoked_at' => $now], 'id = ?', [(int) $row['id']]);
            list($tokens, $newId) = $this->createPair($userId, $deviceId, $channel, $familyId);
            $this->db->update('refresh_tokens', ['replaced_by_id' => $newId], 'id = ?', [(int) $row['id']]);

            return self::outcome(self::RESULT_OK, $userId, $deviceId, $familyId, $tokens);
        });
    }

    /**
     * Jeton d'accès avec l'utilisateur et l'appareil associés, ou null s'il est inconnu.
     */
    public function findAccessToken(string $accessToken): ?array
    {
        return $this->db->fetchOne(
            'SELECT at.id AS token_id, at.user_id, at.device_id, at.refresh_token_id, at.expires_at, at.revoked_at,
                    at.last_used_at, u.uuid AS user_uuid, u.account_type, u.status, u.must_change_password,
                    u.deleted_at AS user_deleted_at, d.revoked_at AS device_revoked_at
             FROM access_tokens at
             JOIN users u ON u.id = at.user_id
             LEFT JOIN devices d ON d.id = at.device_id
             WHERE at.token_hash = ?',
            [hash('sha256', $accessToken)]
        );
    }

    /**
     * Met à jour la date de dernière utilisation au plus une fois par minute (limite les écritures).
     */
    public function touch(int $tokenId, ?string $lastUsedAt): void
    {
        $now = Clock::now();
        if ($lastUsedAt !== null && $now->getTimestamp() - strtotime($lastUsedAt . ' UTC') < 60) {
            return;
        }
        $this->db->update('access_tokens', ['last_used_at' => $now->format('Y-m-d H:i:s')], 'id = ?', [$tokenId]);
    }

    /**
     * Déconnexion : révoque le jeton d'accès et toute sa session (famille de refresh tokens).
     */
    public function revokeSession(int $accessTokenId): void
    {
        $this->db->transaction(function () use ($accessTokenId): void {
            $familyId = $this->familyOfAccessToken($accessTokenId);
            if ($familyId !== null) {
                $this->revokeFamily($familyId);
            }
            $this->db->execute(
                'UPDATE access_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
                [Clock::nowForDatabase(), $accessTokenId]
            );
        });
    }

    /**
     * Révoque toutes les sessions de l'utilisateur, sauf éventuellement celle du jeton d'accès indiqué.
     */
    public function revokeAllForUser(int $userId, ?int $exceptAccessTokenId = null): void
    {
        $now = Clock::nowForDatabase();
        $keepFamily = $exceptAccessTokenId !== null ? $this->familyOfAccessToken($exceptAccessTokenId) : null;

        $this->db->transaction(function () use ($userId, $now, $keepFamily, $exceptAccessTokenId): void {
            if ($keepFamily === null) {
                $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, $userId]);
                $this->db->execute(
                    'UPDATE access_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
                    [$now, $userId, $exceptAccessTokenId ?? 0]
                );
                return;
            }
            $this->db->execute(
                'UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND family_id <> ?',
                [$now, $userId, $keepFamily]
            );
            $this->db->execute(
                'UPDATE access_tokens at
                 LEFT JOIN refresh_tokens rt ON rt.id = at.refresh_token_id
                 SET at.revoked_at = ?
                 WHERE at.user_id = ? AND at.revoked_at IS NULL AND (rt.family_id IS NULL OR rt.family_id <> ?)',
                [$now, $userId, $keepFamily]
            );
        });
    }

    public function revokeDevice(int $deviceId): void
    {
        $now = Clock::nowForDatabase();
        $this->db->transaction(function () use ($deviceId, $now): void {
            $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE device_id = ? AND revoked_at IS NULL', [$now, $deviceId]);
            $this->db->execute('UPDATE access_tokens SET revoked_at = ? WHERE device_id = ? AND revoked_at IS NULL', [$now, $deviceId]);
        });
    }

    public function revokeFamily(string $familyId): void
    {
        $now = Clock::nowForDatabase();
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [$now, $familyId]);
        $this->db->execute(
            'UPDATE access_tokens at
             JOIN refresh_tokens rt ON rt.id = at.refresh_token_id
             SET at.revoked_at = ?
             WHERE rt.family_id = ? AND at.revoked_at IS NULL',
            [$now, $familyId]
        );
    }

    /**
     * @return array{0: array, 1: int} Jetons publics et identifiant du refresh token créé
     */
    private function createPair(int $userId, ?int $deviceId, string $channel, string $familyId): array
    {
        $now = Clock::now();
        $accessTtl = (int) $this->config->get('security.access_token_ttl', 3600);
        $refreshDays = (int) $this->config->get('security.refresh_token_ttl_days', 30);
        $accessExpires = $now->modify('+' . $accessTtl . ' seconds');
        $refreshExpires = $now->modify('+' . $refreshDays . ' days');

        $refreshToken = self::randomToken();
        $refreshId = $this->db->insert('refresh_tokens', [
            'token_hash' => hash('sha256', $refreshToken),
            'family_id' => $familyId,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'expires_at' => $refreshExpires->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        $accessToken = self::randomToken();
        $this->db->insert('access_tokens', [
            'token_hash' => hash('sha256', $accessToken),
            'user_id' => $userId,
            'device_id' => $deviceId,
            'refresh_token_id' => $refreshId,
            'channel' => $channel,
            'expires_at' => $accessExpires->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);

        return [[
            'token_type' => 'Bearer',
            'access_token' => $accessToken,
            'expires_in' => $accessTtl,
            'access_token_expires_at' => $accessExpires->format('Y-m-d\TH:i:s\Z'),
            'refresh_token' => $refreshToken,
            'refresh_token_expires_at' => $refreshExpires->format('Y-m-d\TH:i:s\Z'),
        ], $refreshId];
    }

    private function revokeRefreshToken(int $refreshTokenId, string $now): void
    {
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE id = ?', [$now, $refreshTokenId]);
        $this->db->execute(
            'UPDATE access_tokens SET revoked_at = ? WHERE refresh_token_id = ? AND revoked_at IS NULL',
            [$now, $refreshTokenId]
        );
    }

    private function familyOfAccessToken(int $accessTokenId): ?string
    {
        $family = $this->db->fetchValue(
            'SELECT rt.family_id FROM access_tokens at JOIN refresh_tokens rt ON rt.id = at.refresh_token_id WHERE at.id = ?',
            [$accessTokenId]
        );
        return $family === null ? null : (string) $family;
    }

    private function withinGracePeriod(string $revokedAt): bool
    {
        $grace = (int) $this->config->get('security.refresh_reuse_grace_seconds', 120);
        return Clock::now()->getTimestamp() - strtotime($revokedAt . ' UTC') <= $grace;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function outcome(
        string $result,
        ?int $userId = null,
        ?int $deviceId = null,
        ?string $familyId = null,
        ?array $tokens = null
    ): array {
        return [
            'result' => $result,
            'user_id' => $userId,
            'device_id' => $deviceId,
            'family_id' => $familyId,
            'tokens' => $tokens,
        ];
    }
}
