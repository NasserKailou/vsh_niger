<?php

declare(strict_types=1);

namespace Vsh\Modules\Auth;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

final class DeviceRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM devices WHERE uuid = ?', [$uuid]);
    }

    public function create(array $row): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert('devices', $row + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function update(int $id, array $row): void
    {
        $this->db->update('devices', $row + ['updated_at' => Clock::nowForDatabase()], 'id = ?', [$id]);
    }

    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM devices WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, last_seen_at DESC, id DESC',
            [$userId]
        );
    }
}
