<?php

declare(strict_types=1);

namespace Vsh\Modules\Notifications;

use Vsh\Core\Config;
use Vsh\Core\Container;
use Vsh\Core\Database;
use Vsh\Core\Logger;
use Vsh\Core\Push\PushException;
use Vsh\Core\Push\PushGatewayInterface;
use Vsh\Core\Push\PushMessage;
use Vsh\Core\Sms\SmsException;
use Vsh\Core\Sms\SmsGatewayInterface;
use Vsh\Core\Support\Clock;
use Vsh\Modules\Settings\SettingsService;

/**
 * Traitement de la file des envois push et SMS (D-011), lancé par
 * « php bin/console.php notifications:dispatch » (cron chaque minute).
 *
 * - Les push passent avant les SMS : en mode FALLBACK, un SMS n'est envoyé que si le push n'a pas
 *   abouti (pas d'appareil, service désactivé, échec définitif).
 * - Une notification déjà lue dans l'application, ou trop ancienne, n'est plus envoyée.
 * - Les échecs temporaires sont retentés avec un délai croissant, puis abandonnés (FAILED).
 * - Un verrou nommé empêche deux exécutions simultanées d'envoyer deux fois le même message.
 * - Les passerelles sont résolues à l'usage : une configuration SMS invalide ne bloque pas le push.
 */
final class NotificationDispatcher
{
    private const LOCK_NAME = 'vsh_notification_dispatch';

    /** @var Database */
    private $db;

    /** @var Container */
    private $container;

    /** @var Config */
    private $config;

    /** @var SettingsService */
    private $settings;

    /** @var Logger */
    private $logger;

    public function __construct(Database $db, Container $container, Config $config, SettingsService $settings, Logger $logger)
    {
        $this->db = $db;
        $this->container = $container;
        $this->config = $config;
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * @return array<string,int>|null Envois par résultat (sent, skipped, retry, failed), null si une
     *                                autre exécution est en cours
     */
    public function dispatch(): ?array
    {
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            return null;
        }
        try {
            $counts = ['sent' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
            $rows = $this->db->fetchAll(
                "SELECT d.id, d.notification_id, d.channel, d.attempts,
                        n.uuid AS notification_uuid, n.user_id, n.notif_type, n.title, n.body,
                        n.entity_type, n.entity_uuid, n.read_at, n.created_at AS notified_at,
                        u.phone, u.status AS user_status
                 FROM notification_deliveries d
                 JOIN notifications n ON n.id = d.notification_id
                 JOIN users u ON u.id = n.user_id
                 WHERE d.status = 'PENDING' AND d.next_attempt_at <= ?
                 ORDER BY d.channel = 'SMS', d.id
                 LIMIT ?",
                [Clock::nowForDatabase(), max(1, (int) $this->config->get('notifications.batch_size', 100))]
            );
            foreach ($rows as $row) {
                $counts[$this->process($row)]++;
            }
            return $counts;
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }
    }

    /**
     * @return string sent | skipped | retry | failed
     */
    private function process(array $row): string
    {
        $skip = $this->skipReason($row);
        if ($skip !== null) {
            return $this->finish($row, 'SKIPPED', $skip);
        }
        try {
            return $row['channel'] === 'PUSH' ? $this->sendPush($row) : $this->sendSms($row);
        } catch (PushException | SmsException $exception) {
            return $this->retry($row, $exception->getMessage());
        } catch (\Throwable $exception) {
            // Configuration invalide (fichier FCM absent, identifiants SMS manquants…) : visible dans
            // la supervision et le journal, retentée pour reprendre dès la correction.
            $this->logger->error('Envoi de notification impossible', [
                'channel' => $row['channel'],
                'delivery_id' => (int) $row['id'],
                'error' => $exception->getMessage(),
            ]);
            return $this->retry($row, $exception->getMessage());
        }
    }

    private function skipReason(array $row): ?string
    {
        if ($row['read_at'] !== null) {
            return 'Déjà lue dans l\'application.';
        }
        if ($row['user_status'] === 'SUSPENDED') {
            return 'Compte suspendu.';
        }
        $hours = max(1, (int) $this->config->get('notifications.expire_after_hours', 12));
        $expiresAt = (new \DateTimeImmutable((string) $row['notified_at'], new \DateTimeZone('UTC')))->modify('+' . $hours . ' hours');
        if (Clock::now() > $expiresAt) {
            return 'Expirée : envoyée trop tard pour être utile.';
        }
        return null;
    }

    private function sendPush(array $row): string
    {
        /** @var PushGatewayInterface $push */
        $push = $this->container->get(PushGatewayInterface::class);
        if (!$push->enabled() || !(bool) $this->settings->get('notifications.push_enabled', false)) {
            return $this->finish($row, 'SKIPPED', 'Push désactivé.');
        }
        $tokens = PushTokenService::activeTokens($this->db, (int) $row['user_id']);
        if ($tokens === []) {
            return $this->finish($row, 'SKIPPED', 'Aucun appareil enregistré.');
        }
        $message = new PushMessage((string) $row['title'], (string) ($row['body'] ?? ''), array_filter([
            'notification_id' => (string) $row['notification_uuid'],
            'type' => (string) $row['notif_type'],
            'entity_type' => (string) ($row['entity_type'] ?? ''),
            'entity_id' => (string) ($row['entity_uuid'] ?? ''),
        ], 'strlen'));

        $delivered = false;
        $lastError = null;
        foreach ($tokens as $token) {
            try {
                if ($push->send($token['token'], $message) === PushGatewayInterface::SENT) {
                    $delivered = true;
                } else {
                    $this->db->execute('DELETE FROM push_tokens WHERE id = ?', [$token['id']]);
                }
            } catch (PushException $exception) {
                $lastError = $exception;
            }
        }
        if ($delivered) {
            // Reçu par au moins un appareil : pas de nouvel essai, qui ferait sonner les autres deux fois.
            return $this->finish($row, 'SENT');
        }
        if ($lastError !== null) {
            throw $lastError;
        }
        return $this->finish($row, 'SKIPPED', 'Aucun appareil valide (application désinstallée).');
    }

    private function sendSms(array $row): string
    {
        $mode = (string) $this->settings->get('notifications.sms_mode', NotificationService::SMS_OFF);
        if ($mode === NotificationService::SMS_OFF) {
            return $this->finish($row, 'SKIPPED', 'SMS désactivés.');
        }
        if ((string) $row['phone'] === '') {
            return $this->finish($row, 'SKIPPED', 'Aucun numéro de téléphone.');
        }
        if ($mode === NotificationService::SMS_FALLBACK) {
            $push = $this->db->fetchOne(
                "SELECT status, next_attempt_at FROM notification_deliveries WHERE notification_id = ? AND channel = 'PUSH'",
                [(int) $row['notification_id']]
            );
            if ($push !== null && $push['status'] === 'SENT') {
                return $this->finish($row, 'SKIPPED', 'Reçue par push.');
            }
            if ($push !== null && $push['status'] === 'PENDING') {
                // Le push est retenté : le SMS attend son issue, sans compter de tentative.
                $this->db->update('notification_deliveries', [
                    'next_attempt_at' => $push['next_attempt_at'],
                    'updated_at' => Clock::nowForDatabase(),
                ], 'id = ?', [(int) $row['id']]);
                return 'retry';
            }
        }

        /** @var SmsGatewayInterface $sms */
        $sms = $this->container->get(SmsGatewayInterface::class);
        $sms->send((string) $row['phone'], $this->smsText($row));
        return $this->finish($row, 'SENT');
    }

    /**
     * « Vision Homecare : L'équipe de soins est en route. » — nom de la clinique et texte de la
     * notification, qui ne contient jamais de donnée médicale.
     */
    private function smsText(array $row): string
    {
        $clinic = (string) $this->settings->get('app.clinic_name', 'Vision Homecare');
        $text = trim((string) ($row['body'] ?? '')) !== '' ? (string) $row['body'] : (string) $row['title'];
        $message = $clinic . ' : ' . $text;
        return mb_strlen($message) > 320 ? mb_substr($message, 0, 319) . '…' : $message;
    }

    private function retry(array $row, string $error): string
    {
        $attempts = (int) $row['attempts'] + 1;
        $now = Clock::nowForDatabase();
        if ($attempts >= max(1, (int) $this->config->get('notifications.max_attempts', 5))) {
            $this->db->update('notification_deliveries', [
                'attempts' => $attempts,
                'status' => 'FAILED',
                'last_error' => mb_substr($error, 0, 255),
                'updated_at' => $now,
            ], 'id = ?', [(int) $row['id']]);
            return 'failed';
        }
        $delays = array_values(array_map('intval', (array) $this->config->get('notifications.retry_delays_minutes', [1, 5, 15, 60])));
        $delay = $delays === [] ? 5 : $delays[min($attempts - 1, count($delays) - 1)];
        $this->db->update('notification_deliveries', [
            'attempts' => $attempts,
            'next_attempt_at' => Clock::now()->modify('+' . $delay . ' minutes')->format('Y-m-d H:i:s'),
            'last_error' => mb_substr($error, 0, 255),
            'updated_at' => $now,
        ], 'id = ?', [(int) $row['id']]);
        return 'retry';
    }

    private function finish(array $row, string $status, ?string $reason = null): string
    {
        $now = Clock::nowForDatabase();
        $this->db->update('notification_deliveries', [
            'status' => $status,
            'attempts' => (int) $row['attempts'] + ($status === 'SENT' ? 1 : 0),
            'last_error' => $reason,
            'sent_at' => $status === 'SENT' ? $now : null,
            'updated_at' => $now,
        ], 'id = ?', [(int) $row['id']]);
        if ($status === 'SENT' && $row['channel'] === 'PUSH') {
            $this->db->execute('UPDATE notifications SET pushed_at = ? WHERE id = ?', [$now, (int) $row['notification_id']]);
        }
        return $status === 'SENT' ? 'sent' : 'skipped';
    }

    /**
     * Supervision : réglages actifs, volumes des 7 derniers jours par canal et par état, derniers
     * problèmes (sans le contenu des messages ni le destinataire).
     */
    public function summary(): array
    {
        $since = Clock::now()->modify('-7 days')->format('Y-m-d H:i:s');
        $counts = ['PUSH' => [], 'SMS' => []];
        foreach ($this->db->fetchAll(
            'SELECT channel, status, COUNT(*) AS total FROM notification_deliveries WHERE created_at >= ? GROUP BY channel, status',
            [$since]
        ) as $row) {
            $counts[$row['channel']][$row['status']] = (int) $row['total'];
        }
        $problems = array_map(function (array $row): array {
            return [
                'channel' => (string) $row['channel'],
                'status' => (string) $row['status'],
                'type' => (string) $row['notif_type'],
                'attempts' => (int) $row['attempts'],
                'error' => $row['last_error'],
                'next_attempt_at' => $row['status'] === 'PENDING' ? Clock::toIso((string) $row['next_attempt_at']) : null,
                'updated_at' => Clock::toIso((string) $row['updated_at']),
            ];
        }, $this->db->fetchAll(
            "SELECT d.channel, d.status, d.attempts, d.last_error, d.next_attempt_at, d.updated_at, n.notif_type
             FROM notification_deliveries d JOIN notifications n ON n.id = d.notification_id
             WHERE d.status = 'FAILED' OR (d.status = 'PENDING' AND d.attempts > 0)
             ORDER BY d.updated_at DESC, d.id DESC LIMIT 20"
        ));
        $pushDriver = (string) $this->config->get('notifications.push_driver', 'none');
        return [
            'push' => [
                'driver' => $pushDriver,
                'enabled' => $pushDriver !== 'none' && (bool) $this->settings->get('notifications.push_enabled', false),
                'devices' => (int) $this->db->fetchValue(
                    'SELECT COUNT(*) FROM push_tokens t JOIN devices d ON d.id = t.device_id WHERE d.revoked_at IS NULL'
                ),
            ],
            'sms' => [
                'driver' => (string) $this->config->get('sms.driver', 'log'),
                'mode' => (string) $this->settings->get('notifications.sms_mode', NotificationService::SMS_OFF),
                'types' => array_values((array) $this->settings->get('notifications.sms_types', [])),
            ],
            'last_7_days' => [
                'PUSH' => (object) $counts['PUSH'],
                'SMS' => (object) $counts['SMS'],
            ],
            'pending' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM notification_deliveries WHERE status = 'PENDING'"),
            'problems' => $problems,
        ];
    }
}
