<?php

declare(strict_types=1);

namespace Vsh\Core\Validation;

use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Support\Phone;
use Vsh\Core\Support\Uuid;

/**
 * Validation et normalisation des données entrantes.
 *
 * - Seuls les champs déclarés dans les règles sont retournés (liste blanche : pas d'affectation de masse).
 * - Un champ facultatif absent n'apparaît pas dans le résultat (distingue « absent » de « vidé à null »,
 *   indispensable pour les mises à jour partielles de la synchronisation).
 * - Les chaînes sont nettoyées (trim) ; une chaîne vide vaut null. Le modificateur « raw » conserve la
 *   chaîne telle quelle (mots de passe : les espaces font partie du secret).
 *
 * Règles : required, nullable, raw, string, integer, numeric, boolean, array, email, phone, uuid,
 * date (AAAA-MM-JJ), datetime (normalisé en UTC « AAAA-MM-JJ HH:MM:SS »), in:a,b, min:n, max:n,
 * regex:/motif/, latitude, longitude.
 * Une règle regex contenant « | » doit être passée sous forme de tableau de règles.
 */
final class Validator
{
    private const MODIFIERS = ['required', 'nullable', 'raw'];

    /** @var array<string,string> */
    private $messages;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    /**
     * @param array<string,string|string[]> $rules
     * @throws ValidationException
     */
    public function validate(array $data, array $rules): array
    {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $parsed = $this->parseRules($fieldRules);
            $present = array_key_exists($field, $data);
            $value = $present ? $data[$field] : null;
            if (is_string($value) && !array_key_exists('raw', $parsed)) {
                $value = trim($value);
                if ($value === '') {
                    $value = null;
                }
            }

            if (!$present && !array_key_exists('required', $parsed)) {
                continue;
            }
            if ($value === null) {
                if (array_key_exists('required', $parsed)) {
                    $errors[$field] = [$this->messages['required']];
                } elseif (array_key_exists('nullable', $parsed)) {
                    $validated[$field] = null;
                } else {
                    $errors[$field] = [$this->messages['not_null']];
                }
                continue;
            }

            $failure = null;
            foreach ($parsed as $rule => $argument) {
                if (in_array($rule, self::MODIFIERS, true)) {
                    continue;
                }
                list($valid, $normalized) = $this->apply($rule, $argument, $value);
                if (!$valid) {
                    $failure = $this->message($rule, $argument, $value);
                    break;
                }
                $value = $normalized;
            }

            if ($failure !== null) {
                $errors[$field] = [$failure];
            } else {
                $validated[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $validated;
    }

    /**
     * @param string|string[] $rules
     * @return array<string,string|null>
     */
    private function parseRules($rules): array
    {
        $list = is_array($rules) ? $rules : explode('|', $rules);
        $parsed = [];
        foreach ($list as $rule) {
            $parts = explode(':', (string) $rule, 2);
            $parsed[$parts[0]] = $parts[1] ?? null;
        }
        return $parsed;
    }

    /**
     * @param mixed $value
     * @return array{0: bool, 1: mixed}
     */
    private function apply(string $rule, ?string $argument, $value): array
    {
        switch ($rule) {
            case 'string':
                return [is_string($value), $value];

            case 'integer':
                if (is_int($value)) {
                    return [true, $value];
                }
                if (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1) {
                    return [true, (int) $value];
                }
                if (is_float($value) && floor($value) === $value && abs($value) < PHP_INT_MAX) {
                    return [true, (int) $value];
                }
                return [false, $value];

            case 'numeric':
                if (is_int($value) || is_float($value)) {
                    return [true, $value];
                }
                if (is_string($value) && is_numeric($value)) {
                    return [true, $value + 0];
                }
                return [false, $value];

            case 'boolean':
                if (is_bool($value)) {
                    return [true, $value];
                }
                if (in_array($value, [1, '1', 'true'], true)) {
                    return [true, true];
                }
                if (in_array($value, [0, '0', 'false'], true)) {
                    return [true, false];
                }
                return [false, $value];

            case 'array':
                return [is_array($value), $value];

            case 'email':
                $valid = is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
                return [$valid, $valid ? mb_strtolower($value) : $value];

            case 'phone':
                $normalized = is_string($value) ? Phone::normalize($value) : null;
                return [$normalized !== null, $normalized ?? $value];

            case 'uuid':
                $valid = is_string($value) && Uuid::isValid($value);
                return [$valid, $valid ? strtolower($value) : $value];

            case 'date':
                return $this->validateDate($value);

            case 'datetime':
                return $this->validateDateTime($value);

            case 'in':
                $allowed = explode(',', (string) $argument);
                $valid = (is_string($value) || is_int($value)) && in_array((string) $value, $allowed, true);
                return [$valid, $value];

            case 'min':
            case 'max':
                $size = $this->size($value);
                if ($size === null) {
                    return [false, $value];
                }
                $limit = (float) $argument;
                return [$rule === 'min' ? $size >= $limit : $size <= $limit, $value];

            case 'regex':
                return [is_string($value) && preg_match((string) $argument, $value) === 1, $value];

            case 'latitude':
                return $this->validateCoordinate($value, 90.0);

            case 'longitude':
                return $this->validateCoordinate($value, 180.0);
        }
        throw new \LogicException(sprintf('Règle de validation inconnue : %s', $rule));
    }

    /**
     * @param mixed $value
     * @return array{0: bool, 1: mixed}
     */
    private function validateDate($value): array
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return [false, $value];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
        return [$date !== false && $date->format('Y-m-d') === $value, $value];
    }

    /**
     * Accepte « AAAA-MM-JJ HH:MM[:SS] » (considéré en UTC) ou ISO 8601 avec fuseau ; renvoie l'UTC.
     *
     * @param mixed $value
     * @return array{0: bool, 1: mixed}
     */
    private function validateDateTime($value): array
    {
        $pattern = '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})?$/';
        if (!is_string($value) || preg_match($pattern, $value, $parts) !== 1) {
            return [false, $value];
        }
        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (isset($parts[6]) && $parts[6] !== '' && (int) $parts[6] > 59)) {
            return [false, $value];
        }
        try {
            $dateTime = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception $exception) {
            return [false, $value];
        }
        return [true, $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
    }

    /**
     * @param mixed $value
     * @return array{0: bool, 1: mixed}
     */
    private function validateCoordinate($value, float $limit): array
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (!is_int($value) && !is_float($value)) {
            return [false, $value];
        }
        $value = (float) $value;
        return [$value >= -$limit && $value <= $limit, $value];
    }

    /**
     * @param mixed $value
     * @return float|int|null
     */
    private function size($value)
    {
        if (is_string($value)) {
            return mb_strlen($value);
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_array($value)) {
            return count($value);
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private function message(string $rule, ?string $argument, $value): string
    {
        if ($rule === 'min' || $rule === 'max') {
            $kind = is_string($value) ? 'string' : (is_array($value) ? 'array' : 'numeric');
            $template = $this->messages[$rule . '.' . $kind] ?? $this->messages['in'];
            return str_replace(':' . $rule, (string) $argument, $template);
        }
        return $this->messages[$rule] ?? $this->messages['regex'];
    }
}
