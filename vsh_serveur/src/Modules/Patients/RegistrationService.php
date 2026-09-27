<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Security\OtpService;
use Vsh\Core\Security\PasswordPolicy;
use Vsh\Core\Security\RateLimiter;
use Vsh\Core\Security\TokenService;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Auth\AuthService;
use Vsh\Modules\Notifications\NotificationService;
use Vsh\Modules\Settings\SettingsService;
use Vsh\Modules\Users\RoleRepository;
use Vsh\Modules\Users\UserRepository;
use Vsh\Modules\Users\UserService;

/**
 * Parcours patient : inscription (C1), validation par l'accueil (C2), dossiers de la famille (D-009),
 * espace patient et portail web par n° de dossier + téléphone + code SMS (D-001).
 */
final class RegistrationService
{
    private const CHANNEL_PORTAL = 'PATIENT_PORTAL';
    private const PORTAL_FAILURE = 'N° de dossier, téléphone ou code incorrect.';

    /** @var Database */
    private $db;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientService */
    private $patientService;

    /** @var PatientChildService */
    private $children;

    /** @var UserRepository */
    private $users;

    /** @var RoleRepository */
    private $roles;

    /** @var AuthService */
    private $auth;

    /** @var OtpService */
    private $otp;

    /** @var RateLimiter */
    private $rateLimiter;

    /** @var TokenService */
    private $tokens;

    /** @var SettingsService */
    private $settings;

    /** @var NotificationService */
    private $notifications;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        PatientRepository $patients,
        PatientService $patientService,
        PatientChildService $children,
        UserRepository $users,
        RoleRepository $roles,
        AuthService $auth,
        OtpService $otp,
        RateLimiter $rateLimiter,
        TokenService $tokens,
        SettingsService $settings,
        NotificationService $notifications,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->patientService = $patientService;
        $this->children = $children;
        $this->users = $users;
        $this->roles = $roles;
        $this->auth = $auth;
        $this->otp = $otp;
        $this->rateLimiter = $rateLimiter;
        $this->tokens = $tokens;
        $this->settings = $settings;
        $this->notifications = $notifications;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
    }

    /**
     * @return bool Un code a-t-il été envoyé (false si la vérification par SMS est désactivée)
     */
    public function requestRegistrationCode(array $input, Request $request): bool
    {
        $data = $this->validator->validate($input, ['phone' => 'required|phone']);
        if (!$this->otpRequired()) {
            return false;
        }
        // Le code est envoyé même si le numéro possède déjà un compte : l'information n'est révélée
        // qu'après vérification du code, donc uniquement au détenteur du téléphone.
        $this->otp->send($data['phone'], OtpService::PURPOSE_REGISTRATION, null, $request->ip());
        return true;
    }

    /**
     * @return array{tokens: array, user: array, patient: array}
     */
    public function register(array $input, Request $request): array
    {
        $data = $this->validator->validate($input, PatientService::IDENTITY_RULES + [
            'phone' => 'required|phone',
            'password' => 'required|raw|string|max:200',
            'code' => ['nullable', 'string', 'regex:/^\d{4,8}$/'],
            'device' => 'nullable|array',
            'address' => 'nullable|array',
            'emergency_contact' => 'nullable|array',
        ]);
        PasswordPolicy::assertValid($data['password']);
        $device = isset($data['device']) ? $this->auth->validateDevice($data['device']) : null;
        $addresses = isset($data['address']) ? $this->children->validateList('addresses', [$data['address']], 'address') : [];
        $contacts = isset($data['emergency_contact']) ? $this->children->validateList('contacts', [$data['emergency_contact'] + ['is_emergency' => true]], 'emergency_contact') : [];

        $verified = false;
        if ($this->otpRequired()) {
            if (!isset($data['code'])) {
                throw new ValidationException(['code' => ['Le code reçu par SMS est obligatoire.']]);
            }
            if (!$this->otp->verify($data['phone'], OtpService::PURPOSE_REGISTRATION, $data['code'])) {
                throw new HttpException(422, 'INVALID_OTP', 'Code invalide ou expiré.', ['code' => ['Code invalide ou expiré.']]);
            }
            $verified = true;
        }
        if ($this->users->phoneTaken($data['phone'])) {
            throw HttpException::conflict(
                'Ce numéro possède déjà un compte. Connectez-vous, ou utilisez « mot de passe oublié ».',
                'PHONE_ALREADY_REGISTERED'
            );
        }

        return $this->db->transaction(function () use ($data, $verified, $device, $addresses, $contacts, $request): array {
            $now = Clock::nowForDatabase();
            $userId = $this->users->create([
                'uuid' => Uuid::v4(),
                'account_type' => 'PATIENT',
                'phone' => $data['phone'],
                'password_hash' => PasswordPolicy::hash($data['password']),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'status' => 'PENDING',
                'phone_verified_at' => $verified ? $now : null,
            ]);
            $this->users->replaceRoles($userId, array_values($this->roles->roleIds([UserService::PATIENT_ROLE])), null);
            $patient = $this->createPendingPatient($data, $userId, $data['phone'], $userId);
            foreach ($addresses as $address) {
                $this->children->insert('addresses', (int) $patient['id'], $address, $userId);
            }
            foreach ($contacts as $contact) {
                $this->children->insert('contacts', (int) $patient['id'], $contact, $userId);
            }
            $this->audit->record('PATIENT_REGISTERED', $request, 'patient', (string) $patient['uuid'], null, ['source' => 'APP'], $userId);

            $session = $this->auth->openSession($userId, $device, $request, 'LOGIN');
            return $session + ['patient' => $this->patientService->present((array) $this->patients->findById((int) $patient['id']))];
        });
    }

    /**
     * Ajout d'un proche (enfant…) au compte : nouveau dossier en attente de validation.
     */
    public function addDependent(array $input, Request $request): array
    {
        $auth = self::authContext($request);
        $data = $this->validator->validate($input, PatientService::IDENTITY_RULES);
        $account = (array) $this->users->findById($auth->userId());

        return $this->db->transaction(function () use ($data, $auth, $account, $request): array {
            $patient = $this->createPendingPatient($data, $auth->userId(), $data['phone'] ?? (string) $account['phone'], $auth->userId());
            $this->audit->record('PATIENT_REGISTERED', $request, 'patient', (string) $patient['uuid'], null, ['source' => 'APP', 'dependent' => true]);
            return $this->patientService->present((array) $this->patients->findById((int) $patient['id']));
        });
    }

    public function myPatients(Request $request): array
    {
        return $this->patientService->presentMany($this->patients->forUser(self::authContext($request)->userId()));
    }

    public function myPatient(string $uuid, Request $request): array
    {
        $patient = $this->patients->findByUuid($uuid);
        $auth = self::authContext($request);
        if ($patient === null || $patient['user_id'] === null || (int) $patient['user_id'] !== $auth->userId() || $patient['status'] === 'MERGED') {
            throw HttpException::notFound('Dossier introuvable.');
        }
        if ($patient['status'] !== 'ACTIVE') {
            return $this->patientService->present($patient);
        }
        return $this->patientService->presentDetailed($patient, $request);
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function pending(Pagination $pagination): array
    {
        list($rows, $total) = $this->patients->paginateByStatus('PENDING', $pagination->perPage(), $pagination->offset());
        $items = [];
        foreach ($rows as $row) {
            $item = $this->patientService->present($row);
            $item['duplicates'] = $this->patientService->presentMany($this->patients->duplicateCandidates(
                (string) $row['first_name'],
                (string) $row['last_name'],
                $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
                $row['phone'] !== null ? (string) $row['phone'] : null,
                (int) $row['id']
            ));
            $items[] = $item;
        }
        return [$items, $total];
    }

    public function approve(string $uuid, Request $request): array
    {
        $actorId = self::authContext($request)->userId();
        return $this->db->transaction(function () use ($uuid, $actorId, $request): array {
            $patient = $this->pendingForUpdate($uuid);
            $fileNumber = $this->patientService->nextFileNumber();
            $this->patients->update((int) $patient['id'], [
                'file_number' => $fileNumber,
                'status' => 'ACTIVE',
                'validated_by' => $actorId,
                'validated_at' => Clock::nowForDatabase(),
                'updated_by' => $actorId,
            ]);
            if ($patient['user_id'] !== null) {
                $userId = (int) $patient['user_id'];
                $user = (array) $this->users->findById($userId);
                if ($user['status'] === 'PENDING') {
                    $this->users->update($userId, ['status' => 'ACTIVE']);
                }
                $this->notifications->notify(
                    $userId,
                    'PATIENT_APPROVED',
                    'Dossier validé',
                    sprintf('Le dossier de %s %s a été validé. N° de dossier : %s.', $patient['first_name'], $patient['last_name'], $fileNumber),
                    'patient',
                    (string) $patient['uuid']
                );
            }
            $this->journal->record('patient', (string) $patient['uuid'], ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record('PATIENT_APPROVED', $request, 'patient', (string) $patient['uuid'], ['status' => 'PENDING'], ['status' => 'ACTIVE', 'file_number' => $fileNumber]);
            return $this->patientService->present((array) $this->patients->findById((int) $patient['id']));
        });
    }

    public function reject(string $uuid, array $input, Request $request): array
    {
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
        $actorId = self::authContext($request)->userId();

        return $this->db->transaction(function () use ($uuid, $data, $actorId, $request): array {
            $patient = $this->pendingForUpdate($uuid);
            $this->patients->update((int) $patient['id'], [
                'status' => 'REJECTED',
                'rejection_reason' => $data['reason'],
                'updated_by' => $actorId,
            ]);
            if ($patient['user_id'] !== null) {
                $userId = (int) $patient['user_id'];
                $this->notifications->notify(
                    $userId,
                    'PATIENT_REJECTED',
                    'Demande d\'inscription refusée',
                    'Votre demande n\'a pas pu être validée. Contactez la clinique pour plus d\'informations.',
                    'patient',
                    (string) $patient['uuid']
                );
                // Le compte n'est fermé que s'il ne gère plus aucun dossier ouvert.
                if ($this->patients->countOpenForUser($userId) === 0) {
                    $this->users->update($userId, ['status' => 'REJECTED']);
                    $this->tokens->revokeAllForUser($userId);
                }
            }
            $this->journal->record('patient', (string) $patient['uuid'], ChangeJournal::UPSERT, (int) $patient['id']);
            $this->audit->record('PATIENT_REJECTED', $request, 'patient', (string) $patient['uuid'], ['status' => 'PENDING'], ['status' => 'REJECTED', 'reason' => $data['reason']]);
            return $this->patientService->present((array) $this->patients->findById((int) $patient['id']));
        });
    }

    /**
     * Portail web patient, étape 1 : réponse identique que les informations soient justes ou non.
     */
    public function requestPortalCode(array $input, Request $request): void
    {
        $data = $this->portalIdentity($input);
        $ip = $request->ip();
        $this->rateLimiter->assertAllowed(self::CHANNEL_PORTAL, $data['file_number'], $ip);

        $patient = $this->matchPortalPatient($data['file_number'], $data['phone']);
        if ($patient === null) {
            $this->rateLimiter->hit(self::CHANNEL_PORTAL, $data['file_number'], $ip, false);
            return;
        }
        $this->otp->send($data['phone'], OtpService::PURPOSE_PATIENT_PORTAL, $patient['user_id'] !== null ? (int) $patient['user_id'] : null, $ip);
    }

    /**
     * Portail web patient, étape 2 : ouverture de session. Un dossier créé à la clinique reçoit à cette
     * occasion un compte patient (sans mot de passe), rattaché au téléphone vérifié.
     *
     * @return array{tokens: array, user: array}
     */
    public function portalLogin(array $input, Request $request): array
    {
        $data = $this->portalIdentity($input) + $this->validator->validate($input, [
            'code' => ['required', 'string', 'regex:/^\d{4,8}$/'],
            'device' => 'nullable|array',
        ]);
        // Application mobile : session liée à l'appareil (push, révocation, session de 30 jours sans
        // nouveau SMS). Le portail web n'en déclare pas.
        $device = isset($data['device']) ? $this->auth->validateDevice($data['device']) : null;
        $ip = $request->ip();
        $this->rateLimiter->assertAllowed(self::CHANNEL_PORTAL, $data['file_number'], $ip);

        $patient = $this->matchPortalPatient($data['file_number'], $data['phone']);
        if ($patient === null || !$this->otp->verify($data['phone'], OtpService::PURPOSE_PATIENT_PORTAL, $data['code'])) {
            $this->rateLimiter->hit(self::CHANNEL_PORTAL, $data['file_number'], $ip, false);
            throw HttpException::unauthorized(self::PORTAL_FAILURE, 'INVALID_PORTAL_CREDENTIALS');
        }

        return $this->db->transaction(function () use ($patient, $data, $device, $ip, $request): array {
            $this->rateLimiter->hit(self::CHANNEL_PORTAL, $data['file_number'], $ip, true);
            $userId = $this->accountForPortal($patient, $data['phone']);
            return $this->auth->openSession($userId, $device, $request, 'PATIENT_PORTAL_LOGIN');
        });
    }

    private function accountForPortal(array $patient, string $phone): int
    {
        if ($patient['user_id'] !== null) {
            $user = (array) $this->users->findById((int) $patient['user_id']);
            if (in_array($user['status'], ['SUSPENDED', 'REJECTED'], true)) {
                throw HttpException::forbidden('Ce compte est désactivé. Contactez la clinique.', 'ACCOUNT_DISABLED');
            }
            if ($user['status'] === 'PENDING') {
                $this->users->update((int) $user['id'], ['status' => 'ACTIVE']);
            }
            return (int) $user['id'];
        }

        $existing = $this->users->findByPhone($phone);
        if ($existing !== null && $existing['account_type'] !== 'PATIENT') {
            throw HttpException::conflict(
                'Ce numéro est associé à un compte du personnel. Contactez la clinique pour accéder à votre dossier.',
                'PHONE_USED_BY_STAFF'
            );
        }
        if ($existing !== null) {
            if (in_array($existing['status'], ['SUSPENDED', 'REJECTED'], true)) {
                throw HttpException::forbidden('Ce compte est désactivé. Contactez la clinique.', 'ACCOUNT_DISABLED');
            }
            $userId = (int) $existing['id'];
            if ($existing['status'] === 'PENDING') {
                $this->users->update($userId, ['status' => 'ACTIVE']);
            }
        } else {
            $userId = $this->users->create([
                'uuid' => Uuid::v4(),
                'account_type' => 'PATIENT',
                'phone' => $phone,
                'password_hash' => null,
                'first_name' => (string) $patient['first_name'],
                'last_name' => (string) $patient['last_name'],
                'status' => 'ACTIVE',
                'phone_verified_at' => Clock::nowForDatabase(),
            ]);
            $this->users->replaceRoles($userId, array_values($this->roles->roleIds([UserService::PATIENT_ROLE])), null);
        }
        $this->patients->update((int) $patient['id'], ['user_id' => $userId]);
        $this->journal->record('patient', (string) $patient['uuid'], ChangeJournal::UPSERT, (int) $patient['id']);
        return $userId;
    }

    private function createPendingPatient(array $data, int $userId, string $phone, ?int $actorId): array
    {
        $candidates = $this->patients->duplicateCandidates($data['first_name'], $data['last_name'], $data['birth_date'] ?? null, $phone, null);
        $id = $this->patients->create([
            'uuid' => Uuid::v4(),
            'user_id' => $userId,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'sex' => $data['sex'],
            'birth_date' => $data['birth_date'] ?? null,
            'birth_date_is_estimated' => $data['birth_date_is_estimated'] ?? false,
            'phone' => $phone,
            'status' => 'PENDING',
            'source' => 'APP',
            'possible_duplicate_of' => $candidates !== [] ? (int) $candidates[0]['id'] : null,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);
        $patient = (array) $this->patients->findById($id);
        $this->journal->record('patient', (string) $patient['uuid'], ChangeJournal::UPSERT, $id);
        return $patient;
    }

    private function pendingForUpdate(string $uuid): array
    {
        $patient = $this->patients->findByUuid($uuid, true);
        if ($patient === null) {
            throw HttpException::notFound('Patient introuvable.');
        }
        if ($patient['status'] !== 'PENDING') {
            throw HttpException::conflict('Cette inscription a déjà été traitée.', 'INVALID_STATUS');
        }
        return $patient;
    }

    private function matchPortalPatient(string $fileNumber, string $phone): ?array
    {
        $patient = $this->patients->findByFileNumber($fileNumber);
        if ($patient === null || $patient['status'] !== 'ACTIVE') {
            return null;
        }
        if ($patient['phone'] === $phone) {
            return $patient;
        }
        if ($patient['user_id'] !== null) {
            $user = $this->users->findById((int) $patient['user_id']);
            if ($user !== null && $user['phone'] === $phone) {
                return $patient;
            }
        }
        return null;
    }

    private function portalIdentity(array $input): array
    {
        $data = $this->validator->validate($input, [
            'file_number' => 'required|string|max:30',
            'phone' => 'required|phone',
        ]);
        $data['file_number'] = strtoupper($data['file_number']);
        return $data;
    }

    private function otpRequired(): bool
    {
        return (bool) $this->settings->get('auth.registration_otp_required', true);
    }

    private static function authContext(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        return $auth;
    }
}
