<?php

declare(strict_types=1);

namespace Vsh\Modules\Settings;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Modules\Notifications\NotificationService;

/**
 * Paramètres métier modifiables par l'administration (table settings).
 * Les paramètres techniques et secrets restent dans .env.
 */
final class SettingsService
{
    /** Valeurs autorisées pour les paramètres à liste fermée. */
    private const ALLOWED_VALUES = [
        'homecare.dispatch_mode' => ['BOTH', 'SELF_ASSIGN', 'DISPATCH_ONLY'],
        'app.default_locale' => ['fr', 'en'],
        'notifications.sms_mode' => ['OFF', 'FALLBACK', 'ALWAYS'],
    ];

    /** @var Database */
    private $db;

    /** @var AuditLogger */
    private $audit;

    /** @var array<string,array>|null */
    private $cache;

    public function __construct(Database $db, AuditLogger $audit)
    {
        $this->db = $db;
        $this->audit = $audit;
    }

    /**
     * @param mixed $default
     * @return mixed Valeur typée
     */
    public function get(string $key, $default = null)
    {
        $rows = $this->rows();
        return isset($rows[$key]) ? self::cast($rows[$key]) : $default;
    }

    /**
     * Relecture en base au prochain accès (processus de longue durée, ex. notifications:dispatch --watch).
     */
    public function refresh(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string,mixed> clé => valeur typée
     */
    public function values(bool $publicOnly = false): array
    {
        $values = [];
        foreach ($this->rows() as $key => $row) {
            if (!$publicOnly || (bool) $row['is_public']) {
                $values[$key] = self::cast($row);
            }
        }
        return $values;
    }

    public function list(): array
    {
        return array_values(array_map(function (array $row): array {
            return [
                'key' => (string) $row['setting_key'],
                'value' => self::cast($row),
                'type' => (string) $row['value_type'],
                'description' => $row['description'],
                'is_public' => (bool) $row['is_public'],
                'updated_at' => Clock::toIso((string) $row['updated_at']),
            ];
        }, $this->rows()));
    }

    /**
     * @param array<string,mixed> $values
     */
    public function update(array $values, Request $request): array
    {
        $rows = $this->rows();
        $errors = [];
        $stored = [];
        foreach ($values as $key => $value) {
            if (!isset($rows[$key])) {
                $errors[$key] = ['Paramètre inconnu.'];
                continue;
            }
            $error = self::validate((string) $key, (string) $rows[$key]['value_type'], $value);
            if ($error !== null) {
                $errors[$key] = [$error];
                continue;
            }
            $stored[$key] = self::serialize((string) $rows[$key]['value_type'], $value);
        }
        if ($values === []) {
            $errors['values'] = ['Aucun paramètre à modifier.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $auth = $request->attribute('auth');
        $actorId = $auth instanceof AuthContext ? $auth->userId() : null;
        $this->db->transaction(function () use ($stored, $rows, $actorId, $request): void {
            $old = [];
            $new = [];
            foreach ($stored as $key => $value) {
                if ($rows[$key]['value'] === $value) {
                    continue;
                }
                $this->db->update('settings', [
                    'value' => $value,
                    'updated_by' => $actorId,
                    'updated_at' => Clock::nowForDatabase(),
                ], 'setting_key = ?', [$key]);
                $old[$key] = $rows[$key]['value'];
                $new[$key] = $value;
            }
            if ($new !== []) {
                $this->audit->record('SETTINGS_UPDATED', $request, 'settings', null, $old, $new);
            }
        });
        $this->cache = null;
        return $this->list();
    }

    /**
     * @return array<string,array>
     */
    private function rows(): array
    {
        if ($this->cache === null) {
            $this->cache = [];
            foreach ($this->db->fetchAll('SELECT * FROM settings ORDER BY setting_key') as $row) {
                $this->cache[(string) $row['setting_key']] = $row;
            }
        }
        return $this->cache;
    }

    /**
     * @return mixed
     */
    private static function cast(array $row)
    {
        $value = $row['value'];
        if ($value === null) {
            return null;
        }
        switch ($row['value_type']) {
            case 'BOOL':
                return $value === 'true' || $value === '1';
            case 'INT':
                return (int) $value;
            case 'JSON':
                return json_decode((string) $value, true);
            default:
                return (string) $value;
        }
    }

    /**
     * @param mixed $value
     */
    private static function validate(string $key, string $type, $value): ?string
    {
        switch ($type) {
            case 'BOOL':
                return is_bool($value) ? null : 'Valeur attendue : true ou false.';
            case 'INT':
                return is_int($value) && $value >= 0 ? null : 'Nombre entier positif attendu.';
            case 'JSON':
                if (!is_array($value)) {
                    return 'Liste ou objet JSON attendu.';
                }
                return self::validateJson($key, $value);
        }
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > 500) {
            return 'Texte non vide de 500 caractères au plus attendu.';
        }
        if (isset(self::ALLOWED_VALUES[$key]) && !in_array($value, self::ALLOWED_VALUES[$key], true)) {
            return 'Valeur autorisée : ' . implode(', ', self::ALLOWED_VALUES[$key]) . '.';
        }
        return null;
    }

    /**
     * Structure attendue des paramètres JSON connus : pas de clé inconnue, textes bornés.
     */
    private static function validateJson(string $key, array $value): ?string
    {
        if ($key === 'documents.letterhead') {
            $allowed = ['address', 'phone', 'email', 'invoice_footer', 'prescription_footer'];
            foreach ($value as $field => $text) {
                if (!in_array($field, $allowed, true)) {
                    return 'Champ inconnu : ' . $field . '. Champs possibles : ' . implode(', ', $allowed) . '.';
                }
                if (!is_string($text) || mb_strlen($text) > 255) {
                    return 'Le champ « ' . $field . ' » doit être un texte de 255 caractères au plus.';
                }
            }
        }
        if ($key === 'notifications.sms_types') {
            if (array_values($value) !== $value) {
                return 'Liste de types de notification attendue.';
            }
            foreach ($value as $type) {
                if (!in_array($type, NotificationService::PATIENT_TYPES, true)) {
                    return 'Type inconnu. Types possibles : ' . implode(', ', NotificationService::PATIENT_TYPES) . '.';
                }
            }
        }
        if ($key === 'uploads.allowed_mime_types') {
            if ($value === [] || array_values($value) !== $value) {
                return 'Liste non vide de types de fichiers attendue.';
            }
            foreach ($value as $mime) {
                if (!is_string($mime) || preg_match('#^[a-z]+/[a-z0-9.+-]+$#', $mime) !== 1) {
                    return 'Type de fichier invalide (format attendu : application/pdf).';
                }
            }
        }
        return null;
    }

    /**
     * @param mixed $value
     */
    private static function serialize(string $type, $value): string
    {
        switch ($type) {
            case 'BOOL':
                return $value ? 'true' : 'false';
            case 'INT':
                return (string) $value;
            case 'JSON':
                return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return trim((string) $value);
    }
}
