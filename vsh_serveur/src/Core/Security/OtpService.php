<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Config;
use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Logger;
use Vsh\Core\Sms\SmsException;
use Vsh\Core\Sms\SmsGatewayInterface;
use Vsh\Core\Support\Clock;

/**
 * Codes à usage unique envoyés par SMS (D-001, D-002).
 *
 * - Le code n'est jamais stocké en clair : HMAC-SHA256(APP_KEY, objet|téléphone|code).
 * - Durée de validité courte, nombre d'essais limité, délai minimal entre deux envois, plafond horaire.
 * - Un nouvel envoi invalide les codes précédents du même objet.
 * - Le SMS ne contient aucune donnée médicale.
 */
final class OtpService
{
    public const PURPOSE_REGISTRATION = 'REGISTRATION';
    public const PURPOSE_LOGIN = 'LOGIN';
    public const PURPOSE_PATIENT_PORTAL = 'PATIENT_PORTAL';
    public const PURPOSE_PASSWORD_RESET = 'PASSWORD_RESET';

    /** @var Database */
    private $db;

    /** @var Config */
    private $config;

    /** @var SmsGatewayInterface */
    private $sms;

    /** @var Logger */
    private $logger;

    public function __construct(Database $db, Config $config, SmsGatewayInterface $sms, Logger $logger)
    {
        $this->db = $db;
        $this->config = $config;
        $this->sms = $sms;
        $this->logger = $logger;
    }

    /**
     * @throws HttpException 429 (trop de demandes) ou 503 (SMS indisponible)
     */
    public function send(string $phone, string $purpose, ?int $userId, ?string $ip): void
    {
        $now = Clock::now();
        $cooldown = (int) $this->option('resend_cooldown_seconds', 60);
        $maxPerHour = (int) $this->option('max_per_hour', 5);
        $ttlMinutes = (int) $this->option('ttl_minutes', 5);
        $length = (int) $this->option('length', 6);

        $last = $this->db->fetchValue(
            'SELECT MAX(created_at) FROM otp_codes WHERE phone = ? AND purpose = ?',
            [$phone, $purpose]
        );
        if ($last !== null) {
            $elapsed = $now->getTimestamp() - strtotime($last . ' UTC');
            if ($elapsed < $cooldown) {
                throw HttpException::tooManyRequests($cooldown - $elapsed);
            }
        }
        $hourAgo = $now->modify('-1 hour')->format('Y-m-d H:i:s');
        $recent = $this->db->fetchOne(
            'SELECT COUNT(*) AS total, MIN(created_at) AS oldest FROM otp_codes WHERE phone = ? AND purpose = ? AND created_at > ?',
            [$phone, $purpose, $hourAgo]
        );
        if ((int) $recent['total'] >= $maxPerHour) {
            throw HttpException::tooManyRequests(strtotime($recent['oldest'] . ' UTC') + 3600 - $now->getTimestamp());
        }

        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $nowDb = $now->format('Y-m-d H:i:s');

        $this->db->transaction(function () use ($phone, $purpose, $userId, $ip, $code, $now, $nowDb, $ttlMinutes): void {
            $this->db->execute(
                'UPDATE otp_codes SET expires_at = ? WHERE phone = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > ?',
                [$nowDb, $phone, $purpose, $nowDb]
            );
            $this->db->insert('otp_codes', [
                'phone' => $phone,
                'purpose' => $purpose,
                'user_id' => $userId,
                'code_hash' => $this->hash($phone, $purpose, $code),
                'max_attempts' => (int) $this->option('max_attempts', 5),
                'expires_at' => $now->modify('+' . $ttlMinutes . ' minutes')->format('Y-m-d H:i:s'),
                'ip_address' => $ip,
                'created_at' => $nowDb,
            ]);
        });

        $message = sprintf(
            'Vision Homecare : votre code de vérification est %s. Il expire dans %d minutes. Ne le communiquez à personne.',
            $code,
            $ttlMinutes
        );
        try {
            $this->sms->send($phone, $message);
        } catch (SmsException $exception) {
            $this->logger->error('Échec d\'envoi du SMS OTP', ['purpose' => $purpose, 'error' => $exception->getMessage()]);
            throw new HttpException(503, 'SMS_UNAVAILABLE', "L'envoi du SMS a échoué. Veuillez réessayer dans quelques instants.");
        }
    }

    /**
     * Vérifie et consomme le code. Chaque essai (bon ou mauvais) est décompté.
     */
    public function verify(string $phone, string $purpose, string $code): bool
    {
        return (bool) $this->db->transaction(function () use ($phone, $purpose, $code): bool {
            $now = Clock::nowForDatabase();
            $row = $this->db->fetchOne(
                'SELECT id, code_hash, attempts, max_attempts FROM otp_codes
                 WHERE phone = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > ?
                 ORDER BY id DESC LIMIT 1 FOR UPDATE',
                [$phone, $purpose, $now]
            );
            if ($row === null || (int) $row['attempts'] >= (int) $row['max_attempts']) {
                return false;
            }
            $valid = hash_equals((string) $row['code_hash'], $this->hash($phone, $purpose, $code));
            $this->db->execute(
                'UPDATE otp_codes SET attempts = attempts + 1, consumed_at = ? WHERE id = ?',
                [$valid ? $now : null, (int) $row['id']]
            );
            return $valid;
        });
    }

    private function hash(string $phone, string $purpose, string $code): string
    {
        return hash_hmac('sha256', $purpose . '|' . $phone . '|' . $code, AppKey::from($this->config));
    }

    /**
     * @return mixed
     */
    private function option(string $name, int $default)
    {
        return $this->config->get('security.otp.' . $name, $default);
    }
}
