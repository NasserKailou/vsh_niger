<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

/**
 * Identité de l'utilisateur authentifié pour la requête en cours (attribut « auth » de la Request).
 */
final class AuthContext
{
    /** @var int */
    private $userId;

    /** @var string */
    private $userUuid;

    /** @var string STAFF | PATIENT */
    private $accountType;

    /** @var string[] */
    private $roles;

    /** @var string[] */
    private $permissions;

    /** @var int|null */
    private $deviceId;

    /** @var int */
    private $accessTokenId;

    /** @var bool */
    private $mustChangePassword;

    public function __construct(
        int $userId,
        string $userUuid,
        string $accountType,
        array $roles,
        array $permissions,
        ?int $deviceId,
        int $accessTokenId,
        bool $mustChangePassword
    ) {
        $this->userId = $userId;
        $this->userUuid = $userUuid;
        $this->accountType = $accountType;
        $this->roles = $roles;
        $this->permissions = $permissions;
        $this->deviceId = $deviceId;
        $this->accessTokenId = $accessTokenId;
        $this->mustChangePassword = $mustChangePassword;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function userUuid(): string
    {
        return $this->userUuid;
    }

    public function accountType(): string
    {
        return $this->accountType;
    }

    public function isPatient(): bool
    {
        return $this->accountType === 'PATIENT';
    }

    /**
     * @return string[]
     */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * @return string[]
     */
    public function permissions(): array
    {
        return $this->permissions;
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function deviceId(): ?int
    {
        return $this->deviceId;
    }

    public function accessTokenId(): int
    {
        return $this->accessTokenId;
    }

    public function mustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }
}
