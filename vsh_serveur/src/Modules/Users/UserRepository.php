<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

final class UserRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public function findByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
    }

    public function findByPhone(string $phone): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE phone = ? AND deleted_at IS NULL', [$phone]);
    }

    public function phoneTaken(string $phone, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM users WHERE phone = ? AND id <> ?', [$phone, $exceptId ?? 0]) !== null;
    }

    public function emailTaken(string $email, ?int $exceptId = null): bool
    {
        return $this->db->fetchValue('SELECT id FROM users WHERE email = ? AND id <> ?', [$email, $exceptId ?? 0]) !== null;
    }

    public function create(array $row): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert('users', $row + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function update(int $id, array $row): void
    {
        if ($row === []) {
            return;
        }
        $this->db->update('users', $row + ['updated_at' => Clock::nowForDatabase()], 'id = ?', [$id]);
        $this->db->execute('UPDATE users SET version = version + 1 WHERE id = ?', [$id]);
    }

    /**
     * @return string[]
     */
    public function roleCodes(int $userId): array
    {
        $map = $this->roleCodesForUsers([$userId]);
        return $map[$userId] ?? [];
    }

    /**
     * @param int[] $userIds
     * @return array<int,string[]>
     */
    public function roleCodesForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT ur.user_id, r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')
             ORDER BY r.code',
            array_values($userIds)
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['user_id']][] = (string) $row['code'];
        }
        return $map;
    }

    /**
     * @param int[] $roleIds
     */
    public function replaceRoles(int $userId, array $roleIds, ?int $assignedBy): void
    {
        $this->db->execute('DELETE FROM user_roles WHERE user_id = ?', [$userId]);
        $now = Clock::nowForDatabase();
        foreach (array_unique($roleIds) as $roleId) {
            $this->db->insert('user_roles', [
                'user_id' => $userId,
                'role_id' => $roleId,
                'assigned_by' => $assignedBy,
                'assigned_at' => $now,
            ]);
        }
    }

    public function staffProfile(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT profession, speciality, license_number FROM staff_profiles WHERE user_id = ?',
            [$userId]
        );
    }

    public function saveStaffProfile(int $userId, array $profile): void
    {
        $now = Clock::nowForDatabase();
        if ($this->staffProfile($userId) === null) {
            $this->db->insert('staff_profiles', $profile + ['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
            return;
        }
        if ($profile !== []) {
            $this->db->update('staff_profiles', $profile + ['updated_at' => $now], 'user_id = ?', [$userId]);
        }
    }

    /**
     * @param array{account_type?: string, status?: string, role?: string, search?: string} $filters
     * @return array{0: array[], 1: int} Lignes et total
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = ['u.deleted_at IS NULL'];
        $params = [];
        if (isset($filters['account_type'])) {
            $where[] = 'u.account_type = ?';
            $params[] = $filters['account_type'];
        }
        if (isset($filters['status'])) {
            $where[] = 'u.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['role'])) {
            $where[] = 'EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = u.id AND r.code = ?)';
            $params[] = $filters['role'];
        }
        if (isset($filters['search'])) {
            $like = '%' . addcslashes($filters['search'], '%_\\') . '%';
            $where[] = '(u.first_name LIKE ? OR u.last_name LIKE ? OR u.phone LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM users u WHERE ' . $whereSql, $params);
        $rows = $this->db->fetchAll(
            'SELECT u.* FROM users u WHERE ' . $whereSql . ' ORDER BY u.last_name, u.first_name, u.id LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        );
        return [$rows, $total];
    }

    public function recordFailedLogin(int $id, int $threshold, string $lockUntil): void
    {
        // MySQL évalue les affectations de gauche à droite : failed_login_count est déjà incrémenté ici.
        $this->db->execute(
            'UPDATE users SET failed_login_count = failed_login_count + 1,
                              locked_until = IF(failed_login_count >= ?, ?, locked_until)
             WHERE id = ?',
            [$threshold, $lockUntil, $id]
        );
    }

    public function recordSuccessfulLogin(int $id, ?string $newPasswordHash): void
    {
        $row = ['failed_login_count' => 0, 'locked_until' => null, 'last_login_at' => Clock::nowForDatabase()];
        if ($newPasswordHash !== null) {
            $row['password_hash'] = $newPasswordHash;
        }
        $this->db->update('users', $row, 'id = ?', [$id]);
    }
}
