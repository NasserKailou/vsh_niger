<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Exceptions\ValidationException;

final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 64;

    /** Sans caractères ambigus (0/O, 1/l/I) : les mots de passe temporaires sont souvent dictés. */
    private const TEMPORARY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';

    public static function violation(string $password): ?string
    {
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            return sprintf('Le mot de passe doit contenir au moins %d caractères.', self::MIN_LENGTH);
        }
        if ($length > self::MAX_LENGTH) {
            return sprintf('Le mot de passe doit contenir au plus %d caractères.', self::MAX_LENGTH);
        }
        if (preg_match('/\p{L}/u', $password) !== 1 || preg_match('/\d/', $password) !== 1) {
            return 'Le mot de passe doit contenir au moins une lettre et un chiffre.';
        }
        return null;
    }

    /**
     * @throws ValidationException
     */
    public static function assertValid(string $password, string $field = 'password'): void
    {
        $violation = self::violation($password);
        if ($violation !== null) {
            throw new ValidationException([$field => [$violation]]);
        }
    }

    public static function hash(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function generateTemporary(int $length = 12): string
    {
        $alphabetLength = strlen(self::TEMPORARY_ALPHABET);
        do {
            $password = '';
            for ($i = 0; $i < $length; $i++) {
                $password .= self::TEMPORARY_ALPHABET[random_int(0, $alphabetLength - 1)];
            }
        } while (self::violation($password) !== null);
        return $password;
    }
}
