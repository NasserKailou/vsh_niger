<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AccessControl;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Validation\Validator;

/**
 * Rôles et permissions modifiables par l'administration. Le code de l'application ne vérifie que des
 * codes de permission : créer un rôle « SECRETAIRE » ou modifier « INFIRMIER » ne demande aucun développement.
 */
final class RoleService
{
    /** @var Database */
    private $db;

    /** @var RoleRepository */
    private $roles;

    /** @var AccessControl */
    private $accessControl;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        RoleRepository $roles,
        AccessControl $accessControl,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->roles = $roles;
        $this->accessControl = $accessControl;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    public function list(): array
    {
        return array_map([$this, 'present'], $this->roles->all());
    }

    /**
     * @return array<string, array[]> Permissions groupées par module
     */
    public function permissions(): array
    {
        $grouped = [];
        foreach ($this->roles->permissions() as $permission) {
            $grouped[(string) $permission['module']][] = [
                'code' => (string) $permission['code'],
                'label' => (string) $permission['label'],
            ];
        }
        return $grouped;
    }

    public function create(array $input, Request $request): array
    {
        $data = $this->validator->validate($input, [
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,49}$/'],
            'label' => 'required|string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'required|array|max:200',
        ]);
        if ($this->roles->findByCode($data['code']) !== null) {
            throw new ValidationException(['code' => ['Ce code de rôle existe déjà.']]);
        }
        $permissionIds = $this->resolvePermissions($data['code'], $data['permissions']);

        return $this->db->transaction(function () use ($data, $permissionIds, $request): array {
            $id = $this->roles->create($data['code'], $data['label'], $data['description'] ?? null);
            $this->roles->replacePermissions($id, $permissionIds);
            $role = $this->present((array) $this->roles->findByCode($data['code']));
            $this->audit->record('ROLE_CREATED', $request, 'role', null, null, $role);
            return $role;
        });
    }

    public function update(string $code, array $input, Request $request): array
    {
        $existing = $this->findOrFail($code);
        $data = $this->validator->validate($input, [
            'label' => 'string|max:100',
            'description' => 'nullable|string|max:255',
            'permissions' => 'array|max:200',
        ]);
        $permissionIds = isset($data['permissions']) ? $this->resolvePermissions($code, $data['permissions']) : null;

        return $this->db->transaction(function () use ($existing, $code, $data, $permissionIds, $request): array {
            $before = $this->present($existing);
            $this->roles->update((int) $existing['id'], array_intersect_key($data, array_flip(['label', 'description'])));
            if ($permissionIds !== null) {
                $this->roles->replacePermissions((int) $existing['id'], $permissionIds);
            }
            if ($this->accessControl->countActiveUsersWithPermission('roles.manage') === 0) {
                throw HttpException::conflict(
                    'Opération impossible : au moins un compte actif doit conserver la gestion des rôles et des utilisateurs.',
                    'LAST_ADMINISTRATOR'
                );
            }
            $after = $this->present((array) $this->roles->findByCode($code));
            list($old, $new) = AuditLogger::changes($before, $after);
            if ($new !== []) {
                $this->audit->record('ROLE_UPDATED', $request, 'role', null, $old + ['code' => $code], $new + ['code' => $code]);
            }
            return $after;
        });
    }

    public function delete(string $code, Request $request): void
    {
        $role = $this->findOrFail($code);
        if ((bool) $role['is_system']) {
            throw HttpException::conflict('Les rôles fournis à l\'installation ne peuvent pas être supprimés.', 'SYSTEM_ROLE');
        }
        if ((int) $role['user_count'] > 0) {
            throw HttpException::conflict('Ce rôle est encore attribué à des utilisateurs.', 'ROLE_IN_USE');
        }
        $this->db->transaction(function () use ($role, $request): void {
            $this->roles->delete((int) $role['id']);
            $this->audit->record('ROLE_DELETED', $request, 'role', null, $this->present($role), null);
        });
    }

    public function present(array $role): array
    {
        return [
            'code' => (string) $role['code'],
            'label' => (string) $role['label'],
            'description' => $role['description'] !== null ? (string) $role['description'] : null,
            'is_system' => (bool) $role['is_system'],
            'user_count' => (int) $role['user_count'],
            'permissions' => $role['permissions'],
        ];
    }

    /**
     * @param mixed $codes
     * @return int[]
     */
    private function resolvePermissions(string $roleCode, $codes): array
    {
        if (!is_array($codes)) {
            throw new ValidationException(['permissions' => ['Liste de permissions invalide.']]);
        }
        foreach ($codes as $permission) {
            if (!is_string($permission)) {
                throw new ValidationException(['permissions' => ['Liste de permissions invalide.']]);
            }
        }
        // Garde-fou : un compte patient ne doit jamais recevoir de permission donnant accès aux dossiers des autres.
        if ($roleCode === UserService::PATIENT_ROLE) {
            foreach ($codes as $permission) {
                if (!self::isPatientScoped($permission)) {
                    throw new ValidationException(['permissions' => [
                        sprintf('La permission « %s » ne peut pas être attribuée au rôle PATIENT.', $permission),
                    ]]);
                }
            }
        }
        $ids = $this->roles->permissionIds($codes);
        $unknown = array_diff(array_unique($codes), array_keys($ids));
        if ($unknown !== []) {
            throw new ValidationException(['permissions' => ['Permission(s) inconnue(s) : ' . implode(', ', $unknown) . '.']]);
        }
        return array_values($ids);
    }

    private static function isPatientScoped(string $permission): bool
    {
        return strpos($permission, 'self.') === 0 || substr($permission, -5) === '_self';
    }

    private function findOrFail(string $code): array
    {
        $role = $this->roles->findByCode($code);
        if ($role === null) {
            throw HttpException::notFound('Rôle introuvable.');
        }
        return $role;
    }
}
