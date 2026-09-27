<?php

declare(strict_types=1);

namespace Vsh\Modules\Audit;

use Vsh\Core\Database;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Support\Clock;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Settings\SettingsService;

/**
 * Consultation du journal d'audit (droit `audit.read`) : lecture seule, filtres par action, objet,
 * auteur et période (jours locaux de l'établissement). Le journal n'est jamais modifiable par l'API.
 */
final class AuditService
{
    /** @var Database */
    private $db;

    /** @var Validator */
    private $validator;

    /** @var SettingsService */
    private $settings;

    public function __construct(Database $db, Validator $validator, SettingsService $settings)
    {
        $this->db = $db;
        $this->validator = $validator;
        $this->settings = $settings;
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination): array
    {
        $data = $this->validator->validate($query, [
            'action' => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/'],
            'entity_type' => ['nullable', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'entity_id' => 'nullable|uuid',
            'user_id' => 'nullable|uuid',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        $where = ['1 = 1'];
        $params = [];
        if (isset($data['action'])) {
            $where[] = 'a.action = ?';
            $params[] = $data['action'];
        }
        if (isset($data['entity_type'])) {
            $where[] = 'a.entity_type = ?';
            $params[] = $data['entity_type'];
        }
        if (isset($data['entity_id'])) {
            $where[] = 'a.entity_uuid = ?';
            $params[] = $data['entity_id'];
        }
        if (isset($data['user_id'])) {
            $where[] = 'u.uuid = ?';
            $params[] = $data['user_id'];
        }
        // Jours locaux (fuseau de l'établissement) convertis en bornes UTC.
        if (isset($data['from'])) {
            $where[] = 'a.created_at >= ?';
            $params[] = $this->utcBoundary($data['from'], false);
        }
        if (isset($data['to'])) {
            $where[] = 'a.created_at < ?';
            $params[] = $this->utcBoundary($data['to'], true);
        }
        $sql = ' FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN devices d ON d.id = a.device_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT a.*, u.uuid AS user_uuid, u.first_name, u.last_name, u.account_type, d.uuid AS device_uuid, d.device_name'
            . $sql . ' ORDER BY a.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map([$this, 'present'], $rows), $total];
    }

    /**
     * Valeurs connues pour les filtres (actions et types d'objet déjà présents dans le journal).
     */
    public function facets(): array
    {
        return [
            'actions' => array_map('strval', array_column($this->db->fetchAll('SELECT DISTINCT action FROM audit_logs ORDER BY action LIMIT 500'), 'action')),
            'entity_types' => array_map('strval', array_column(
                $this->db->fetchAll('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type LIMIT 200'),
                'entity_type'
            )),
        ];
    }

    private function present(array $row): array
    {
        return [
            'id' => (string) $row['id'],
            'action' => (string) $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_uuid'],
            'user' => $row['user_uuid'] !== null ? [
                'id' => (string) $row['user_uuid'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
                'is_patient' => $row['account_type'] === 'PATIENT',
            ] : null,
            'device' => $row['device_uuid'] !== null ? ['id' => (string) $row['device_uuid'], 'name' => $row['device_name']] : null,
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'] !== null ? mb_substr((string) $row['user_agent'], 0, 160) : null,
            'request_id' => $row['request_id'],
            'old_values' => self::decode($row['old_values']),
            'new_values' => self::decode($row['new_values']),
            'created_at' => Clock::toIso((string) $row['created_at']),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function decode($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function utcBoundary(string $day, bool $endExclusive): string
    {
        try {
            $zone = new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
        } catch (\Exception $e) {
            $zone = new \DateTimeZone('Africa/Niamey');
        }
        $local = new \DateTimeImmutable($day . ' 00:00:00', $zone);
        if ($endExclusive) {
            $local = $local->modify('+1 day');
        }
        return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
