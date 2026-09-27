<?php

declare(strict_types=1);

namespace Vsh\Modules\Auth;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Logger;
use Vsh\Core\Security\AccessControl;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Security\OtpService;
use Vsh\Core\Security\PasswordPolicy;
use Vsh\Core\Security\RateLimiter;
use Vsh\Core\Security\TokenService;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Users\UserRepository;
use Vsh\Modules\Users\UserService;

/**
 * Connexion par téléphone + mot de passe, sessions par appareil, mot de passe oublié par OTP SMS.
 *
 * Les messages d'échec ne révèlent jamais si un numéro existe.
 */
final class AuthService
{
    private const CHANNEL_LOGIN = 'LOGIN';
    private const CHANNEL_PASSWORD_RESET = 'PASSWORD_RESET';
    private const INVALID_CREDENTIALS = 'Numéro de téléphone ou mot de passe incorrect.';

    /** @var Database */
    private $db;

    /** @var UserRepository */
    private $users;

    /** @var UserService */
    private $userService;

    /** @var DeviceRepository */
    private $devices;

    /** @var TokenService */
    private $tokens;

    /** @var AccessControl */
    private $accessControl;

    /** @var RateLimiter */
    private $rateLimiter;

    /** @var OtpService */
    private $otp;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    /** @var Logger */
    private $logger;

    /** @var string|null */
    private static $dummyHash;

    public function __construct(
        Database $db,
        UserRepository $users,
        UserService $userService,
        DeviceRepository $devices,
        TokenService $tokens,
        AccessControl $accessControl,
        RateLimiter $rateLimiter,
        OtpService $otp,
        AuditLogger $audit,
        Validator $validator,
        Logger $logger
    ) {
        $this->db = $db;
        $this->users = $users;
        $this->userService = $userService;
        $this->devices = $devices;
        $this->tokens = $tokens;
        $this->accessControl = $accessControl;
        $this->rateLimiter = $rateLimiter;
        $this->otp = $otp;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->logger = $logger;
    }

    /**
     * @return array{tokens: array, user: array}
     */
    public function login(array $input, Request $request): array
    {
        $data = $this->validator->validate($input, [
            'phone' => 'required|phone',
            'password' => 'required|raw|string|max:200',
            'device' => 'nullable|array',
        ]);
        $device = isset($data['device']) ? $this->validateDevice($data['device']) : null;
        $ip = $request->ip();

        $this->rateLimiter->assertAllowed(self::CHANNEL_LOGIN, $data['phone'], $ip);

        $user = $this->users->findByPhone($data['phone']);
        $passwordOk = $this->checkPassword($data['password'], $user !== null ? $user['password_hash'] : null);

        if (!$passwordOk) {
            $this->db->transaction(function () use ($data, $ip, $user, $request): void {
                $this->rateLimiter->hit(self::CHANNEL_LOGIN, $data['phone'], $ip, false);
                if ($user !== null) {
                    $this->users->recordFailedLogin((int) $user['id'], $this->rateLimiter->maxFailures(), $this->rateLimiter->lockUntil());
                }
                $this->audit->record('LOGIN_FAILED', $request, 'user', $user !== null ? (string) $user['uuid'] : null, null, null, $user !== null ? (int) $user['id'] : null);
            });
            throw HttpException::unauthorized(self::INVALID_CREDENTIALS, 'INVALID_CREDENTIALS');
        }

        $userId = (int) $user['id'];
        $status = (string) $user['status'];
        if ($status === 'SUSPENDED' || $status === 'REJECTED' || ($status === 'PENDING' && $user['account_type'] !== 'PATIENT')) {
            throw HttpException::forbidden('Ce compte est désactivé. Contactez la clinique.', 'ACCOUNT_DISABLED');
        }

        return $this->db->transaction(function () use ($user, $userId, $data, $device, $ip, $request): array {
            $this->rateLimiter->hit(self::CHANNEL_LOGIN, $data['phone'], $ip, true);
            $newHash = password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT) ? PasswordPolicy::hash($data['password']) : null;
            $this->users->recordSuccessfulLogin($userId, $newHash);

            return $this->openSession($userId, $device, $request, 'LOGIN');
        });
    }

    /**
     * Ouvre une session (jetons) pour un utilisateur déjà authentifié par un autre moyen
     * (mot de passe, inscription, code SMS du portail patient).
     *
     * @param array|null $device Appareil déjà validé par validateDevice()
     * @return array{tokens: array, user: array}
     */
    public function openSession(int $userId, ?array $device, Request $request, string $auditAction): array
    {
        return $this->db->transaction(function () use ($userId, $device, $request, $auditAction): array {
            $deviceId = $device !== null ? $this->registerDevice($device, $userId) : null;
            $tokens = $this->tokens->issue($userId, $deviceId, $deviceId !== null ? 'MOBILE' : 'WEB');
            $user = (array) $this->users->findById($userId);
            $this->audit->record($auditAction, $request, 'user', (string) $user['uuid'], null, $device !== null ? ['device' => $device['uuid']] : null, $userId);
            return ['tokens' => $tokens, 'user' => $this->profile($userId)];
        });
    }

    /**
     * @return array{tokens: array}
     */
    public function refresh(array $input, Request $request): array
    {
        $data = $this->validator->validate($input, ['refresh_token' => 'required|raw|string|max:200']);
        $outcome = $this->tokens->rotate($data['refresh_token']);

        switch ($outcome['result']) {
            case TokenService::RESULT_OK:
                break;
            case TokenService::RESULT_REUSED:
                $this->logger->warning('Réutilisation d\'un refresh token : session révoquée', ['user_id' => $outcome['user_id']]);
                $this->audit->record('REFRESH_TOKEN_REUSED', $request, 'user', null, null, ['family' => $outcome['family_id']], $outcome['user_id']);
                throw HttpException::unauthorized('Session révoquée par sécurité. Veuillez vous reconnecter.', 'REFRESH_TOKEN_REUSED');
            case TokenService::RESULT_EXPIRED:
                throw HttpException::unauthorized('Session expirée. Veuillez vous reconnecter.', 'SESSION_EXPIRED');
            case TokenService::RESULT_REVOKED:
                throw HttpException::unauthorized('Session terminée. Veuillez vous reconnecter.', 'SESSION_REVOKED');
            default:
                throw HttpException::unauthorized('Session invalide. Veuillez vous reconnecter.', 'INVALID_REFRESH_TOKEN');
        }

        $user = $this->users->findById((int) $outcome['user_id']);
        $allowed = $user !== null && ($user['status'] === 'ACTIVE' || ($user['status'] === 'PENDING' && $user['account_type'] === 'PATIENT'));
        if (!$allowed) {
            $this->tokens->revokeFamily((string) $outcome['family_id']);
            throw HttpException::unauthorized('Ce compte est désactivé.', 'ACCOUNT_DISABLED');
        }
        if ($outcome['device_id'] !== null) {
            $this->devices->update((int) $outcome['device_id'], ['last_seen_at' => Clock::nowForDatabase()]);
        }
        return ['tokens' => $outcome['tokens']];
    }

    public function logout(Request $request): void
    {
        $auth = self::auth($request);
        $this->db->transaction(function () use ($auth, $request): void {
            $this->tokens->revokeSession($auth->accessTokenId());
            if ($auth->deviceId() !== null) {
                // Plus aucun push sur un appareil déconnecté (D-011).
                $this->db->execute('DELETE FROM push_tokens WHERE device_id = ?', [$auth->deviceId()]);
            }
            $this->audit->record('LOGOUT', $request, 'user', $auth->userUuid());
        });
    }

    public function me(Request $request): array
    {
        return $this->profile(self::auth($request)->userId());
    }

    public function changePassword(array $input, Request $request): void
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'current_password' => 'required|raw|string|max:200',
            'new_password' => 'required|raw|string|max:200',
        ]);
        $user = (array) $this->users->findById($auth->userId());
        if (!$this->checkPassword($data['current_password'], $user['password_hash'])) {
            throw new ValidationException(['current_password' => ['Mot de passe actuel incorrect.']]);
        }
        PasswordPolicy::assertValid($data['new_password'], 'new_password');
        if (password_verify($data['new_password'], (string) $user['password_hash'])) {
            throw new ValidationException(['new_password' => ['Le nouveau mot de passe doit être différent de l\'actuel.']]);
        }

        $this->db->transaction(function () use ($auth, $data, $request): void {
            $this->users->update($auth->userId(), [
                'password_hash' => PasswordPolicy::hash($data['new_password']),
                'must_change_password' => false,
                'updated_by' => $auth->userId(),
            ]);
            // Les autres appareils sont déconnectés ; la session en cours est conservée.
            $this->tokens->revokeAllForUser($auth->userId(), $auth->accessTokenId());
            $this->audit->record('PASSWORD_CHANGED', $request, 'user', $auth->userUuid());
        });
    }

    /**
     * Réponse identique que le numéro existe ou non.
     */
    public function forgotPassword(array $input, Request $request): void
    {
        $data = $this->validator->validate($input, ['phone' => 'required|phone']);
        $user = $this->users->findByPhone($data['phone']);
        if ($user === null || !in_array($user['status'], ['ACTIVE', 'PENDING'], true)) {
            return;
        }
        $this->otp->send($data['phone'], OtpService::PURPOSE_PASSWORD_RESET, (int) $user['id'], $request->ip());
        $this->audit->record('PASSWORD_RESET_REQUESTED', $request, 'user', (string) $user['uuid'], null, null, (int) $user['id']);
    }

    public function resetPassword(array $input, Request $request): void
    {
        $data = $this->validator->validate($input, [
            'phone' => 'required|phone',
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'new_password' => 'required|raw|string|max:200',
        ]);
        $ip = $request->ip();
        $this->rateLimiter->assertAllowed(self::CHANNEL_PASSWORD_RESET, $data['phone'], $ip);
        PasswordPolicy::assertValid($data['new_password'], 'new_password');

        $user = $this->users->findByPhone($data['phone']);
        $valid = $user !== null && $this->otp->verify($data['phone'], OtpService::PURPOSE_PASSWORD_RESET, $data['code']);
        if (!$valid) {
            $this->rateLimiter->hit(self::CHANNEL_PASSWORD_RESET, $data['phone'], $ip, false);
            throw new HttpException(422, 'INVALID_OTP', 'Code invalide ou expiré.', ['code' => ['Code invalide ou expiré.']]);
        }

        $this->db->transaction(function () use ($user, $data, $ip, $request): void {
            $this->rateLimiter->hit(self::CHANNEL_PASSWORD_RESET, $data['phone'], $ip, true);
            $this->users->update((int) $user['id'], [
                'password_hash' => PasswordPolicy::hash($data['new_password']),
                'must_change_password' => false,
                'failed_login_count' => 0,
                'locked_until' => null,
                'phone_verified_at' => Clock::nowForDatabase(),
            ]);
            $this->tokens->revokeAllForUser((int) $user['id']);
            $this->audit->record('PASSWORD_RESET', $request, 'user', (string) $user['uuid'], null, null, (int) $user['id']);
        });
    }

    public function devices(Request $request): array
    {
        $auth = self::auth($request);
        return array_map(function (array $device) use ($auth): array {
            return [
                'id' => (string) $device['uuid'],
                'platform' => (string) $device['platform'],
                'device_name' => $device['device_name'],
                'app_version' => $device['app_version'],
                'last_seen_at' => Clock::toIso($device['last_seen_at']),
                'last_sync_at' => Clock::toIso($device['last_sync_at']),
                'revoked_at' => Clock::toIso($device['revoked_at']),
                'current' => $auth->deviceId() === (int) $device['id'],
            ];
        }, $this->devices->forUser($auth->userId()));
    }

    public function revokeDevice(string $deviceUuid, Request $request): void
    {
        $auth = self::auth($request);
        $device = $this->devices->findByUuid($deviceUuid);
        if ($device === null || (int) $device['user_id'] !== $auth->userId()) {
            throw HttpException::notFound('Appareil introuvable.');
        }
        if ($device['revoked_at'] !== null) {
            return;
        }
        $this->db->transaction(function () use ($device, $auth, $request): void {
            $this->devices->update((int) $device['id'], ['revoked_at' => Clock::nowForDatabase(), 'revoked_by' => $auth->userId()]);
            $this->tokens->revokeDevice((int) $device['id']);
            $this->audit->record('DEVICE_REVOKED', $request, 'device', (string) $device['uuid']);
        });
    }

    private function profile(int $userId): array
    {
        $user = (array) $this->users->findById($userId);
        $profile = $this->userService->present($user, $this->users->roleCodes($userId), $this->users->staffProfile($userId));
        $profile['permissions'] = $this->accessControl->rolesAndPermissions($userId)['permissions'];
        return $profile;
    }

    /**
     * Enregistre l'appareil, ou le rattache à l'utilisateur qui s'y connecte (appareil partagé).
     * Dans ce cas, les sessions du précédent utilisateur sur cet appareil sont fermées ; l'application
     * doit effacer les données locales du précédent utilisateur (voir docs/05).
     */
    private function registerDevice(array $device, int $userId): int
    {
        $existing = $this->devices->findByUuid($device['uuid']);
        $now = Clock::nowForDatabase();
        $attributes = [
            'platform' => $device['platform'],
            'device_name' => $device['name'] ?? null,
            'app_version' => $device['app_version'] ?? null,
            'last_seen_at' => $now,
        ];
        if ($existing === null) {
            return $this->devices->create($attributes + ['uuid' => $device['uuid'], 'user_id' => $userId]);
        }
        if ($existing['revoked_at'] !== null) {
            throw HttpException::forbidden('Cet appareil a été révoqué. Contactez la clinique.', 'DEVICE_REVOKED');
        }
        if ((int) $existing['user_id'] !== $userId) {
            $this->tokens->revokeDevice((int) $existing['id']);
            $attributes['user_id'] = $userId;
        }
        $this->devices->update((int) $existing['id'], $attributes);
        return (int) $existing['id'];
    }

    /**
     * @param mixed $device
     */
    public function validateDevice($device): array
    {
        try {
            return $this->validator->validate((array) $device, [
                'uuid' => 'required|uuid',
                'platform' => 'required|in:ANDROID,IOS',
                'name' => 'nullable|string|max:100',
                'app_version' => 'nullable|string|max:20',
            ]);
        } catch (ValidationException $exception) {
            $errors = [];
            foreach ($exception->getErrors() as $field => $messages) {
                $errors['device.' . $field] = $messages;
            }
            throw new ValidationException($errors);
        }
    }

    /**
     * Vérifie le mot de passe en temps constant, y compris pour un compte inexistant (pas d'énumération par la durée).
     */
    private function checkPassword(string $password, ?string $hash): bool
    {
        if ($hash === null || $hash === '') {
            if (self::$dummyHash === null) {
                self::$dummyHash = password_hash(Uuid::v4(), PASSWORD_DEFAULT);
            }
            password_verify($password, self::$dummyHash);
            return false;
        }
        return password_verify($password, $hash);
    }

    private static function auth(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        return $auth;
    }
}
