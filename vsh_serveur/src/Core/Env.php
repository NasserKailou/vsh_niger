<?php

declare(strict_types=1);

namespace Vsh\Core;

/**
 * Lecture du fichier .env. Les variables d'environnement réelles du serveur sont prioritaires.
 */
final class Env
{
    /** @var array<string,string> */
    private static $values = [];

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $separator = strpos($line, '=');
            if ($separator === false) {
                continue;
            }
            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));
            $quote = $value !== '' ? $value[0] : '';
            if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && substr($value, -1) === $quote) {
                $value = substr($value, 1, -1);
            } else {
                $comment = strpos($value, ' #');
                if ($comment !== false) {
                    $value = rtrim(substr($value, 0, $comment));
                }
            }
            self::$values[$key] = $value;
        }
    }

    /**
     * Une valeur vide est traitée comme absente.
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            $value = array_key_exists($key, self::$values) ? self::$values[$key] : null;
        }
        return ($value === null || $value === '') ? $default : $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default): int
    {
        $value = self::get($key);
        return ($value !== null && preg_match('/^-?\d+$/', $value) === 1) ? (int) $value : $default;
    }

    /**
     * Liste séparée par des virgules.
     *
     * @return string[]
     */
    public static function csv(string $key): array
    {
        $value = self::get($key);
        if ($value === null) {
            return [];
        }
        $items = array_map('trim', explode(',', $value));
        return array_values(array_filter($items, function (string $item): bool {
            return $item !== '';
        }));
    }
}
