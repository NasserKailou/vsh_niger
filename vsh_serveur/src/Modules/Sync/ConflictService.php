<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Validation\Validator;

/**
 * Conflits de synchronisation : un même champ a été modifié sur l'appareil (hors ligne) et sur le serveur.
 * La valeur du serveur est conservée en attendant l'arbitrage ; la valeur de l'appareil n'est pas perdue.
 */
final class ConflictService
{
    public const OPEN = 'OUVERT';

    private const RESOLUTIONS = [
        'SERVER' => 'RESOLU_SERVEUR',
        'CLIENT' => 'RESOLU_CLIENT',
        'MERGE' => 'RESOLU_FUSION',
    ];

    /** @var Database */
    private $db;

    /** @var SyncRegistry */
    private $registry;

    /** @var Validator */
    private $validator;

    /** @var AuditLogger */
    private $audit;

    public function __construct(Database $db, SyncRegistry $registry, Validator $validator, AuditLogger $audit)
    {
        $this->db = $db;
        $this->registry = $registry;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    /**
     * @param array<string,array{base: mixed, client: mixed, server: mixed}> $conflicts
     */
    public function record(string $uuid, int $operationId, AuthContext $auth, array $op, array $conflicts, ?array $serverState): void
    {
        $clientValues = [];
        foreach ($conflicts as $field => $values) {
            $clientValues[$field] = $values['client'];
        }
        $this->db->insert('sync_conflicts', [
            'uuid' => $uuid,
            'sync_operation_id' => $operationId,
            'user_id' => $auth->userId(),
            'entity' => $op['entity'],
            'entity_uuid' => $op['entity_id'],
            'conflicting_fields' => self::json(array_keys($conflicts)),
            'client_payload' => self::json($clientValues),
            'server_state' => self::json($serverState ?? []),
            'status' => self::OPEN,
            'created_at' => Clock::nowForDatabase(),
        ]);
    }

    public function list(array $query, AuthContext $auth): array
    {
        $filters = $this->validator->validate($query, [
            'status' => 'nullable|in:OUVERT,RESOLU_SERVEUR,RESOLU_CLIENT,RESOLU_FUSION',
            'all' => 'nullable|boolean',
        ]);
        $where = ['status = ?'];
        $params = [$filters['status'] ?? self::OPEN];
        if (empty($filters['all']) || !$auth->can('sync.supervise')) {
            $where[] = 'user_id = ?';
            $params[] = $auth->userId();
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM sync_conflicts WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 200',
            $params
        );
        return array_map([self::class, 'present'], $rows);
    }

    public function countOpen(int $userId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM sync_conflicts WHERE user_id = ? AND status = ?', [$userId, self::OPEN]);
    }

    /**
     * SERVER : on garde la valeur du serveur. CLIENT : la valeur de l'appareil est appliquée.
     * MERGE : les valeurs fournies (« values ») sont appliquées aux champs en conflit.
     */
    public function resolve(string $uuid, array $input, Request $request, AuthContext $auth): array
    {
        $data = $this->validator->validate($input, [
            'resolution' => 'required|in:SERVER,CLIENT,MERGE',
            'values' => 'nullable|array',
        ]);
        $conflict = $this->db->fetchOne('SELECT * FROM sync_conflicts WHERE uuid = ?', [$uuid]);
        if ($conflict === null || ((int) $conflict['user_id'] !== $auth->userId() && !$auth->can('sync.supervise'))) {
            throw HttpException::notFound('Conflit introuvable.');
        }
        if ($conflict['status'] !== self::OPEN) {
            throw HttpException::conflict('Ce conflit a déjà été résolu.', 'CONFLICT_RESOLVED');
        }

        $fields = (array) json_decode((string) $conflict['conflicting_fields'], true);
        $values = [];
        if ($data['resolution'] === 'CLIENT') {
            $values = (array) json_decode((string) $conflict['client_payload'], true);
        } elseif ($data['resolution'] === 'MERGE') {
            $values = (array) ($data['values'] ?? []);
            $unexpected = array_diff(array_keys($values), $fields);
            if ($values === [] || $unexpected !== []) {
                throw new ValidationException(['values' => ['Indiquez une valeur pour les champs en conflit uniquement : ' . implode(', ', $fields) . '.']]);
            }
        }

        return $this->db->transaction(function () use ($conflict, $data, $values, $request, $auth): array {
            if ($values !== []) {
                $handler = $this->registry->get((string) $conflict['entity']);
                if ($handler === null || !$handler->supports('UPDATE')) {
                    throw HttpException::conflict('Cette entité ne peut plus être modifiée.', 'UNSUPPORTED_OPERATION');
                }
                $handler->update((string) $conflict['entity_uuid'], $values, $request);
            }
            $status = self::RESOLUTIONS[$data['resolution']];
            $this->db->update('sync_conflicts', [
                'status' => $status,
                'resolved_by' => $auth->userId(),
                'resolved_at' => Clock::nowForDatabase(),
            ], 'id = ?', [(int) $conflict['id']]);
            $this->audit->record('SYNC_CONFLICT_RESOLVED', $request, (string) $conflict['entity'], (string) $conflict['entity_uuid'], null, [
                'resolution' => $data['resolution'],
                'fields' => array_keys($values),
            ]);
            return self::present((array) $this->db->fetchOne('SELECT * FROM sync_conflicts WHERE id = ?', [(int) $conflict['id']]));
        });
    }

    public static function present(array $row): array
    {
        $fields = (array) json_decode((string) $row['conflicting_fields'], true);
        $client = (array) json_decode((string) $row['client_payload'], true);
        $server = (array) json_decode((string) $row['server_state'], true);
        $details = [];
        foreach ($fields as $field) {
            $details[$field] = ['client' => $client[$field] ?? null, 'server' => $server[$field] ?? null];
        }
        return [
            'id' => (string) $row['uuid'],
            'entity' => (string) $row['entity'],
            'entity_id' => (string) $row['entity_uuid'],
            'fields' => $fields,
            'details' => $details,
            'status' => (string) $row['status'],
            'created_at' => Clock::toIso((string) $row['created_at']),
            'resolved_at' => Clock::toIso($row['resolved_at']),
        ];
    }

    /**
     * @param mixed $value
     */
    private static function json($value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
