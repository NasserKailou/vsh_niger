<?php

declare(strict_types=1);

namespace Vsh\Core\Support;

/**
 * Normalisation des numéros de téléphone au format E.164 (+22790123456).
 * Accepte les saisies courantes : « 90 12 34 56 », « 0022790123456 », « 227 90-12-34-56 ».
 */
final class Phone
{
    /** @var string */
    private static $defaultCountryCode = '227';

    /** @var int Longueur des numéros nationaux (Niger : 8 chiffres) */
    private static $nationalLength = 8;

    public static function setDefaultCountryCode(string $countryCode, int $nationalLength = 8): void
    {
        self::$defaultCountryCode = ltrim($countryCode, '+');
        self::$nationalLength = $nationalLength;
    }

    public static function normalize(string $raw): ?string
    {
        $number = (string) preg_replace('/[\s.\-()\/]/', '', $raw);
        if (strpos($number, '00') === 0) {
            $number = '+' . substr($number, 2);
        }
        if ($number !== '' && $number[0] !== '+') {
            $national = '/^\d{' . self::$nationalLength . '}$/';
            $withCountry = '/^' . preg_quote(self::$defaultCountryCode, '/') . '\d{' . self::$nationalLength . '}$/';
            if (preg_match($national, $number) === 1) {
                $number = '+' . self::$defaultCountryCode . $number;
            } elseif (preg_match($withCountry, $number) === 1) {
                $number = '+' . $number;
            } else {
                return null;
            }
        }
        return preg_match('/^\+[1-9]\d{7,14}$/', $number) === 1 ? $number : null;
    }
}
