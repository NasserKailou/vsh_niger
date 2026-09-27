<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Config;
use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Support\Clock;

/**
 * Limitation des tentatives (connexion, réinitialisation…) par identifiant et par adresse IP.
 *
 * L'identifiant (téléphone, n° de dossier) est stocké sous forme de HMAC avec APP_KEY : un simple SHA-256
 * d'un numéro à 8 chiffres se retrouverait par force brute en quelques secondes.
 * Le compteur d'un identifiant repart de zéro après une tentative réussie.
 */
final class RateLimiter
{
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
     * @throws HttpException 429 si la limite est atteinte
     */
    public function assertAllowed(string $channel, string $identifier, ?string $ip): void
    {
        $window = $this->windowSeconds();
        $since = Clock::now()->modify('-' . $window . ' seconds')->format('Y-m-d H:i:s');
        $hash = $this->hashIdentifier($identifier);

        $lastSuccess = $this->db->fetchValue(
            'SELECT MAX(attempted_at) FROM login_attempts WHERE identifier_hash = ? AND channel = ? AND success = 1',
            [$hash, $channel]
        );
        $from = ($lastSuccess !== null && (string) $lastSuccess > $since) ? (string) $lastSuccess : $since;

        $byIdentifier = $this->db->fetchOne(
            'SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest
             FROM login_attempts
             WHERE identifier_hash = ? AND channel = ? AND success = 0 AND attempted_at > ?',
            [$hash, $channel, $from]
        );
        if ((int) $byIdentifier['failures'] >= (int) $this->config->get('security.login.max_failures', 5)) {
            throw HttpException::tooManyRequests($this->retryAfter((string) $byIdentifier['oldest'], $window));
        }

        if ($ip !== null) {
            $byIp = $this->db->fetchOne(
                'SELECT COUNT(*) AS failures, MIN(attempted_at) AS oldest
                 FROM login_attempts
                 WHERE ip_address = ? AND channel = ? AND success = 0 AND attempted_at > ?',
                [$ip, $channel, $since]
            );
            if ((int) $byIp['failures'] >= (int) $this->config->get('security.login.ip_max_failures', 30)) {
                throw HttpException::tooManyRequests($this->retryAfter((string) $byIp['oldest'], $window));
            }
        }
    }

    public function hit(string $channel, string $identifier, ?string $ip, bool $success): void
    {
        $this->db->insert('login_attempts', [
            'identifier_hash' => $this->hashIdentifier($identifier),
            'ip_address' => $ip,
            'channel' => $channel,
            'success' => $success,
            'attempted_at' => Clock::nowForDatabase(),
        ]);
    }

    /**
     * Fin de verrouillage à afficher sur le compte lorsque le seuil d'échecs est atteint.
     */
    public function lockUntil(): string
    {
        return Clock::now()->modify('+' . $this->windowSeconds() . ' seconds')->format('Y-m-d H:i:s');
    }

    public function maxFailures(): int
    {
        return (int) $this->config->get('security.login.max_failures', 5);
    }

    private function windowSeconds(): int
    {
        return 60 * (int) $this->config->get('security.login.window_minutes', 15);
    }

    private function retryAfter(string $oldestFailure, int $window): int
    {
        return max(1, strtotime($oldestFailure . ' UTC') + $window - Clock::now()->getTimestamp());
    }

    private function hashIdentifier(string $identifier): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($identifier)), AppKey::from($this->config));
    }
}
