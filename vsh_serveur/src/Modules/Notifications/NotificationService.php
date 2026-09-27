<?php

declare(strict_types=1);

namespace Vsh\Modules\Notifications;

use Vsh\Core\Config;
use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Modules\Settings\SettingsService;

/**
 * Notifications in-app (table notifications). C'est la source de vérité : l'application les récupère
 * à la synchronisation, avec ou sans notification push. Le titre et le texte ne contiennent jamais
 * de donnée médicale.
 *
 * Les envois push et SMS (D-011) sont mis en file dans la même transaction que la notification,
 * puis traités hors requête par NotificationDispatcher : aucun appel réseau pendant une transaction,
 * et rien n'est envoyé si l'action qui a produit la notification est annulée.
 */
final class NotificationService
{
    public const SMS_OFF = 'OFF';
    public const SMS_FALLBACK = 'FALLBACK';
    public const SMS_ALWAYS = 'ALWAYS';

    /** Types de notification adressés aux patients, seuls candidats à l'envoi par SMS. */
    public const PATIENT_TYPES = [
        'PATIENT_APPROVED',
        'PATIENT_REJECTED',
        'APPOINTMENT_STATUS',
        'HOMECARE_STATUS',
        'EXAM_RESULT',
        'PRESCRIPTION_SIGNED',
        'INVOICE_ISSUED',
    ];

    /** @var Database */
    private $db;

    /** @var ChangeJournal */
    private $journal;

    /** @var SettingsService */
    private $settings;

    /** @var Config */
    private $config;

    public function __construct(Database $db, ChangeJournal $journal, SettingsService $settings, Config $config)
    {
        $this->db = $db;
        $this->journal = $journal;
        $this->settings = $settings;
        $this->config = $config;
    }

    public function notify(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $entityType = null,
        ?string $entityUuid = null
    ): void {
        $uuid = Uuid::v4();
        $now = Clock::nowForDatabase();
        $notificationId = $this->db->insert('notifications', [
            'uuid' => $uuid,
            'user_id' => $userId,
            'notif_type' => $type,
            'title' => $title,
            'body' => $body,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'created_at' => $now,
        ]);
        $this->journal->record('notification', $uuid, ChangeJournal::UPSERT, null, null, $userId);

        foreach ($this->channelsFor($userId, $type) as $channel) {
            $this->db->insert('notification_deliveries', [
                'notification_id' => $notificationId,
                'channel' => $channel,
                'status' => 'PENDING',
                'next_attempt_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Canaux d'envoi : push si le service est actif et que l'utilisateur a un appareil enregistré ;
     * SMS pour les patients, selon le mode et les types choisis par l'administrateur.
     *
     * @return string[]
     */
    private function channelsFor(int $userId, string $type): array
    {
        $channels = [];
        if ($this->pushEnabled() && PushTokenService::hasActiveToken($this->db, $userId)) {
            $channels[] = 'PUSH';
        }
        $smsTypes = (array) $this->settings->get('notifications.sms_types', []);
        if ($this->settings->get('notifications.sms_mode', self::SMS_OFF) !== self::SMS_OFF && in_array($type, $smsTypes, true)) {
            $user = $this->db->fetchOne('SELECT account_type, phone FROM users WHERE id = ?', [$userId]);
            if ($user !== null && $user['account_type'] === 'PATIENT' && (string) $user['phone'] !== '') {
                $channels[] = 'SMS';
            }
        }
        return $channels;
    }

    /**
     * Push actif : pilote configuré (PUSH_DRIVER) et activation par l'administrateur. La passerelle
     * n'est pas construite ici : une configuration FCM invalide ne doit bloquer aucune action métier.
     */
    public function pushEnabled(): bool
    {
        return (string) $this->config->get('notifications.push_driver', 'none') !== 'none'
            && (bool) $this->settings->get('notifications.push_enabled', false);
    }

    /**
     * @return array{0: array[], 1: int, 2: int} Notifications, total, non lues
     */
    public function list(int $userId, Pagination $pagination, bool $unreadOnly): array
    {
        $where = 'user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '');
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE ' . $where, [$userId]);
        $unread = (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
        $rows = $this->db->fetchAll(
            'SELECT * FROM notifications WHERE ' . $where . ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?',
            [$userId, $pagination->perPage(), $pagination->offset()]
        );
        return [array_map([self::class, 'present'], $rows), $total, $unread];
    }

    public function markRead(int $userId, string $uuid): void
    {
        $affected = $this->db->execute(
            'UPDATE notifications SET read_at = COALESCE(read_at, ?) WHERE uuid = ? AND user_id = ?',
            [Clock::nowForDatabase(), $uuid, $userId]
        );
        if ($affected === 0 && $this->db->fetchValue('SELECT id FROM notifications WHERE uuid = ? AND user_id = ?', [$uuid, $userId]) === null) {
            throw HttpException::notFound('Notification introuvable.');
        }
    }

    public function markAllRead(int $userId): int
    {
        return $this->db->execute(
            'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL',
            [Clock::nowForDatabase(), $userId]
        );
    }

    public static function present(array $row): array
    {
        return [
            'id' => (string) $row['uuid'],
            'type' => (string) $row['notif_type'],
            'title' => (string) $row['title'],
            'body' => $row['body'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_uuid'],
            'read_at' => Clock::toIso($row['read_at']),
            'created_at' => Clock::toIso((string) $row['created_at']),
        ];
    }
}
