<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Database;

/**
 * Rôles et permissions effectifs d'un utilisateur (RBAC stocké en base).
 */
final class AccessControl
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{roles: string[], permissions: string[]}
     */
    public function rolesAndPermissions(int $userId): array
    {
        $roles = $this->db->fetchAll(
            'SELECT r.code FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.code',
            [$userId]
        );
        $permissions = $this->db->fetchAll(
            'SELECT DISTINCT p.code
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = ?
             ORDER BY p.code',
            [$userId]
        );
        return [
            'roles' => array_map(function (array $row): string {
                return (string) $row['code'];
            }, $roles),
            'permissions' => array_map(function (array $row): string {
                return (string) $row['code'];
            }, $permissions),
        ];
    }

    public function countActiveUsersWithPermission(string $permission): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(DISTINCT u.id)
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.code = ? AND u.status = 'ACTIVE' AND u.deleted_at IS NULL",
            [$permission]
        );
    }
}
