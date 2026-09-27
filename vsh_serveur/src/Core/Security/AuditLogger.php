<?php

declare(strict_types=1);

namespace Vsh\Core\Security;

use Vsh\Core\Database;
use Vsh\Core\Http\Request;
use Vsh\Core\Logger;
use Vsh\Core\Support\Clock;

/**
 * Journal d'audit des actions sensibles (table audit_logs).
 * Les valeurs sont filtrées : mots de passe, jetons, codes et secrets ne sont jamais enregistrés.
 * L'écriture fait partie de la transaction de l'opération : si l'audit échoue, l'opération échoue.
 */
final class AuditLogger
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function record(
        string $action,
        ?Request $request = null,
        ?string $entityType = null,
        ?string $entityUuid = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null
    ): void {
        $auth = $request !== null ? $request->attribute('auth') : null;
        if ($userId === null && $auth instanceof AuthContext) {
            $userId = $auth->userId();
        }
        $this->db->insert('audit_logs', [
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'old_values' => self::encode($oldValues),
            'new_values' => self::encode($newValues),
            'ip_address' => $request !== null ? $request->ip() : null,
            'user_agent' => $request !== null ? $request->userAgent() : null,
            'device_id' => $auth instanceof AuthContext ? $auth->deviceId() : null,
            'request_id' => $request !== null ? $request->attribute('request_id') : null,
            'created_at' => Clock::nowForDatabase(),
        ]);
    }

    /**
     * Ne conserve que les champs réellement modifiés.
     *
     * @return array{0: array, 1: array} Anciennes et nouvelles valeurs
     */
    public static function changes(array $before, array $after): array
    {
        $old = [];
        $new = [];
        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;
            if ($previous !== $value) {
                $old[$key] = $previous;
                $new[$key] = $value;
            }
        }
        return [$old, $new];
    }

    private static function encode(?array $values): ?string
    {
        if ($values === null) {
            return null;
        }
        $json = json_encode(Logger::redact($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        return $json === false ? null : $json;
    }
}
