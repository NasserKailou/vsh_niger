<?php

declare(strict_types=1);

namespace Vsh\Modules\Users;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AccessControl;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Security\PasswordPolicy;
use Vsh\Core\Security\TokenService;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;

/**
 * Gestion des comptes du personnel. Les comptes patients sont gérés par le module Patients.
 */
final class UserService
{
    /** Permission qui doit toujours rester détenue par au moins un compte actif (évite de se retrouver sans administrateur). */
    private const ADMINISTRATION_PERMISSION = 'roles.manage';

    /** Rôle réservé aux comptes patients (créé par les seeds). */
    public const PATIENT_ROLE = 'PATIENT';

    private const PROFESSIONS = 'MEDECIN,INFIRMIER,SAGE_FEMME,TECHNICIEN,ADMINISTRATIF,AUTRE';

    /** @var Database */
    private $db;

    /** @var UserRepository */
    private $users;

    /** @var RoleRepository */
    private $roles;

    /** @var AccessControl */
    private $accessControl;

    /** @var TokenService */
    private $tokens;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        UserRepository $users,
        RoleRepository $roles,
        AccessControl $accessControl,
        TokenService $tokens,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->users = $users;
        $this->roles = $roles;
        $this->accessControl = $accessControl;
        $this->tokens = $tokens;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination): array
    {
        $filters = $this->validator->validate($query, [
            'account_type' => 'nullable|in:STAFF,PATIENT',
            'status' => 'nullable|in:PENDING,ACTIVE,SUSPENDED,REJECTED',
            'role' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:100',
        ]);
        $filters = array_filter($filters, function ($value): bool {
            return $value !== null;
        }) + ['account_type' => 'STAFF'];

        list($rows, $total) = $this->users->paginate($filters, $pagination->perPage(), $pagination->offset());
        $roles = $this->users->roleCodesForUsers(array_map(function (array $row): int {
            return (int) $row['id'];
        }, $rows));
        $items = array_map(function (array $row) use ($roles): array {
            return $this->present($row, $roles[(int) $row['id']] ?? [], null);
        }, $rows);
        return [$items, $total];
    }

    public function get(string $uuid): array
    {
        $user = $this->findOrFail($uuid);
        return $this->present($user, $this->users->roleCodes((int) $user['id']), $this->users->staffProfile((int) $user['id']));
    }

    /**
     * @return array{user: array, temporary_password: string}
     */
    public function createStaff(array $input, Request $request): array
    {
        $data = $this->validator->validate($input, $this->rules(true));
        $roleIds = $this->resolveStaffRoles($data['roles']);
        $this->assertUnique($data, null);

        $temporaryPassword = PasswordPolicy::generateTemporary();
        $actorId = self::actorId($request);

        $user = $this->db->transaction(function () use ($data, $roleIds, $temporaryPassword, $actorId, $request): array {
            $id = $this->users->create([
                'uuid' => Uuid::v4(),
                'account_type' => 'STAFF',
                'phone' => $data['phone'],
                'email' => $data['email'] ?? null,
                'password_hash' => PasswordPolicy::hash($temporaryPassword),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'status' => 'ACTIVE',
                'must_change_password' => true,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
            $this->users->replaceRoles($id, $roleIds, $actorId);
            $this->users->saveStaffProfile($id, self::profileFields($data));

            $user = $this->get((string) $this->users->findById($id)['uuid']);
            $this->audit->record('USER_CREATED', $request, 'user', $user['id'], null, $user);
            return $user;
        });

        return ['user' => $user, 'temporary_password' => $temporaryPassword];
    }

    public function update(string $uuid, array $input, Request $request): array
    {
        $existing = $this->findStaffOrFail($uuid);
        $id = (int) $existing['id'];
        $data = $this->validator->validate($input, $this->rules(false));
        $roleIds = isset($data['roles']) ? $this->resolveStaffRoles($data['roles']) : null;
        $this->assertUnique($data, $id);
        $actorId = self::actorId($request);

        return $this->db->transaction(function () use ($uuid, $id, $data, $roleIds, $actorId, $request): array {
            $before = $this->get($uuid);
            $fields = array_intersect_key($data, array_flip(['first_name', 'last_name', 'phone', 'email']));
            if ($fields !== []) {
                $this->users->update($id, $fields + ['updated_by' => $actorId]);
            }
            if ($roleIds !== null) {
                $this->users->replaceRoles($id, $roleIds, $actorId);
            }
            $profile = self::profileFields($data);
            if ($profile !== []) {
                $this->users->saveStaffProfile($id, $profile);
            }
            $this->assertAdministrationRemains();

            $after = $this->get($uuid);
            list($old, $new) = AuditLogger::changes($before, $after);
            if ($new !== []) {
                $this->audit->record('USER_UPDATED', $request, 'user', $uuid, $old, $new);
            }
            return $after;
        });
    }

    public function suspend(string $uuid, Request $request): array
    {
        $user = $this->findStaffOrFail($uuid);
        if ((int) $user['id'] === self::actorId($request)) {
            throw HttpException::conflict('Vous ne pouvez pas suspendre votre propre compte.', 'SELF_ACTION');
        }
        return $this->changeStatus($user, 'SUSPENDED', 'USER_SUSPENDED', $request);
    }

    public function activate(string $uuid, Request $request): array
    {
        return $this->changeStatus($this->findStaffOrFail($uuid), 'ACTIVE', 'USER_ACTIVATED', $request);
    }

    /**
     * @return array{user: array, temporary_password: string}
     */
    public function resetPassword(string $uuid, Request $request): array
    {
        $user = $this->findStaffOrFail($uuid);
        $temporaryPassword = PasswordPolicy::generateTemporary();
        $actorId = self::actorId($request);

        $this->db->transaction(function () use ($user, $temporaryPassword, $actorId, $request): void {
            $this->users->update((int) $user['id'], [
                'password_hash' => PasswordPolicy::hash($temporaryPassword),
                'must_change_password' => true,
                'failed_login_count' => 0,
                'locked_until' => null,
                'updated_by' => $actorId,
            ]);
            $this->tokens->revokeAllForUser((int) $user['id']);
            $this->audit->record('USER_PASSWORD_RESET', $request, 'user', (string) $user['uuid']);
        });

        return ['user' => $this->get((string) $user['uuid']), 'temporary_password' => $temporaryPassword];
    }

    /**
     * Représentation publique d'un compte (jamais le hachage du mot de passe ni l'identifiant interne).
     *
     * @param string[] $roles
     */
    public function present(array $user, array $roles, ?array $profile): array
    {
        return [
            'id' => (string) $user['uuid'],
            'account_type' => (string) $user['account_type'],
            'first_name' => (string) $user['first_name'],
            'last_name' => (string) $user['last_name'],
            'phone' => (string) $user['phone'],
            'email' => $user['email'] !== null ? (string) $user['email'] : null,
            'status' => (string) $user['status'],
            'must_change_password' => (bool) $user['must_change_password'],
            'locked_until' => Clock::toIso($user['locked_until'] !== null ? (string) $user['locked_until'] : null),
            'last_login_at' => Clock::toIso($user['last_login_at'] !== null ? (string) $user['last_login_at'] : null),
            'roles' => $roles,
            'staff_profile' => $profile === null ? null : [
                'profession' => (string) $profile['profession'],
                'speciality' => $profile['speciality'],
                'license_number' => $profile['license_number'],
            ],
            'created_at' => Clock::toIso((string) $user['created_at']),
            'updated_at' => Clock::toIso((string) $user['updated_at']),
        ];
    }

    private function changeStatus(array $user, string $status, string $action, Request $request): array
    {
        if ((string) $user['status'] === $status) {
            return $this->get((string) $user['uuid']);
        }
        return $this->db->transaction(function () use ($user, $status, $action, $request): array {
            $this->users->update((int) $user['id'], ['status' => $status, 'updated_by' => self::actorId($request)]);
            if ($status !== 'ACTIVE') {
                $this->tokens->revokeAllForUser((int) $user['id']);
            }
            $this->assertAdministrationRemains();
            $this->audit->record($action, $request, 'user', (string) $user['uuid'], ['status' => $user['status']], ['status' => $status]);
            return $this->get((string) $user['uuid']);
        });
    }

    /**
     * Appelé à l'intérieur de la transaction : l'exception annule la modification.
     */
    private function assertAdministrationRemains(): void
    {
        if ($this->accessControl->countActiveUsersWithPermission(self::ADMINISTRATION_PERMISSION) === 0) {
            throw HttpException::conflict(
                'Opération impossible : au moins un compte actif doit conserver la gestion des rôles et des utilisateurs.',
                'LAST_ADMINISTRATOR'
            );
        }
    }

    /**
     * @param mixed $codes
     * @return int[]
     */
    private function resolveStaffRoles($codes): array
    {
        $invalid = ['roles' => ['Liste de rôles invalide.']];
        if (!is_array($codes) || $codes === []) {
            throw new ValidationException(['roles' => ['Au moins un rôle est obligatoire.']]);
        }
        foreach ($codes as $code) {
            if (!is_string($code)) {
                throw new ValidationException($invalid);
            }
        }
        if (in_array(self::PATIENT_ROLE, $codes, true)) {
            throw new ValidationException(['roles' => ['Le rôle PATIENT est réservé aux comptes patients.']]);
        }
        $ids = $this->roles->roleIds($codes);
        $unknown = array_diff(array_unique($codes), array_keys($ids));
        if ($unknown !== []) {
            throw new ValidationException(['roles' => ['Rôle(s) inconnu(s) : ' . implode(', ', $unknown) . '.']]);
        }
        return array_values($ids);
    }

    private function assertUnique(array $data, ?int $exceptId): void
    {
        $errors = [];
        if (isset($data['phone']) && $this->users->phoneTaken($data['phone'], $exceptId)) {
            $errors['phone'] = ['Ce numéro de téléphone est déjà utilisé.'];
        }
        if (isset($data['email']) && $this->users->emailTaken($data['email'], $exceptId)) {
            $errors['email'] = ['Cette adresse e-mail est déjà utilisée.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function rules(bool $creation): array
    {
        $required = $creation ? 'required|' : '';
        return [
            'first_name' => $required . 'string|max:100',
            'last_name' => $required . 'string|max:100',
            'phone' => $required . 'phone',
            'email' => 'nullable|email|max:190',
            'roles' => $required . 'array|min:1|max:20',
            'profession' => $required . 'in:' . self::PROFESSIONS,
            'speciality' => 'nullable|string|max:150',
            'license_number' => 'nullable|string|max:50',
        ];
    }

    private static function profileFields(array $data): array
    {
        return array_intersect_key($data, array_flip(['profession', 'speciality', 'license_number']));
    }

    private function findOrFail(string $uuid): array
    {
        $user = $this->users->findByUuid($uuid);
        if ($user === null) {
            throw HttpException::notFound('Utilisateur introuvable.');
        }
        return $user;
    }

    private function findStaffOrFail(string $uuid): array
    {
        $user = $this->findOrFail($uuid);
        if ($user['account_type'] !== 'STAFF') {
            throw HttpException::conflict('Les comptes patients se gèrent depuis le module Patients.', 'PATIENT_ACCOUNT');
        }
        return $user;
    }

    private static function actorId(Request $request): ?int
    {
        $auth = $request->attribute('auth');
        return $auth instanceof AuthContext ? $auth->userId() : null;
    }
}
