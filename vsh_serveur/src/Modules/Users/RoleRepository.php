<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

final class RoleRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return array[] Rôles avec leurs codes de permission et leur nombre d'utilisateurs
     */
    public function all(): array
    {
        $roles = $this->db->fetchAll(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count
             FROM roles r ORDER BY r.is_system DESC, r.code'
        );
        $permissions = $this->db->fetchAll(
            'SELECT rp.role_id, p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id ORDER BY p.code'
        );
        $byRole = [];
        foreach ($permissions as $row) {
            $byRole[(int) $row['role_id']][] = (string) $row['code'];
        }
        foreach ($roles as &$role) {
            $role['permissions'] = $byRole[(int) $role['id']] ?? [];
        }
        unset($role);
        return $roles;
    }

    public function findByCode(string $code): ?array
    {
        $role = $this->db->fetchOne(
            'SELECT r.*, (SELECT COUNT(*) FROM user_roles ur WHERE ur.role_id = r.id) AS user_count FROM roles r WHERE r.code = ?',
            [$code]
        );
        if ($role === null) {
            return null;
        }
        $role['permissions'] = array_map(function (array $row): string {
            return (string) $row['code'];
        }, $this->db->fetchAll(
            'SELECT p.code FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.code',
            [(int) $role['id']]
        ));
        return $role;
    }

    /**
     * @param string[] $codes
     * @return array<string,int> code => id des rôles existants
     */
    public function roleIds(array $codes): array
    {
        return $this->idsByCode('roles', $codes);
    }

    /**
     * @param string[] $codes
     * @return array<string,int> code => id des permissions existantes
     */
    public function permissionIds(array $codes): array
    {
        return $this->idsByCode('permissions', $codes);
    }

    public function permissions(): array
    {
        return $this->db->fetchAll('SELECT code, module, label FROM permissions ORDER BY module, code');
    }

    public function create(string $code, string $label, ?string $description): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert('roles', [
            'code' => $code,
            'label' => $label,
            'description' => $description,
            'is_system' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(int $id, array $row): void
    {
        if ($row !== []) {
            $this->db->update('roles', $row + ['updated_at' => Clock::nowForDatabase()], 'id = ?', [$id]);
        }
    }

    /**
     * @param int[] $permissionIds
     */
    public function replacePermissions(int $roleId, array $permissionIds): void
    {
        $this->db->execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
        foreach (array_unique($permissionIds) as $permissionId) {
            $this->db->insert('role_permissions', ['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM roles WHERE id = ?', [$id]);
    }

    /**
     * @param string[] $codes
     * @return array<string,int>
     */
    private function idsByCode(string $table, array $codes): array
    {
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, code FROM `' . $table . '` WHERE code IN (' . implode(', ', array_fill(0, count($codes), '?')) . ')',
            $codes
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['code']] = (int) $row['id'];
        }
        return $map;
    }
}
