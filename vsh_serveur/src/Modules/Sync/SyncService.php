<?php

declare(strict_types=1);

namespace Vsh\Modules\Sync;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Logger;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Teams\TeamService;

/**
 * Moteur de synchronisation côté serveur (rapport §E).
 *
 * PUSH — chaque opération est :
 *  - idempotente : (appareil, op_id) n'est exécutée qu'une fois ; un renvoi retourne le résultat enregistré ;
 *  - isolée : une transaction par opération, une erreur n'annule pas les autres ;
 *  - ordonnée : une opération qui dépend d'une autre non appliquée est reportée (DEFERRED) ;
 *  - fusionnée champ par champ (UPDATE) : l'appareil envoie la valeur de départ (« base ») de chaque champ
 *    modifié ; un champ modifié entre-temps sur le serveur avec une autre valeur devient un conflit.
 *
 * PULL — journal sync_changes lu à partir du curseur, filtré selon le périmètre et les droits de l'utilisateur.
 */
final class SyncService
{
    public const APPLIED = 'APPLIED';
    public const CONFLICT = 'CONFLICT';
    public const REJECTED = 'REJECTED';
    public const DEFERRED = 'DEFERRED';
    public const ERROR = 'ERROR';

    private const MAX_OPERATIONS = 50;
    private const DEFAULT_PULL_LIMIT = 200;
    private const MAX_PULL_LIMIT = 500;

    /** Champs gérés par le serveur, jamais modifiables par un appareil. */
    private const SERVER_FIELDS = ['id', 'version', 'created_at', 'updated_at', 'patient_id'];

    /** @var Database */
    private $db;

    /** @var SyncRegistry */
    private $registry;

    /** @var SyncScope */
    private $scope;

    /** @var ConflictService */
    private $conflicts;

    /** @var Validator */
    private $validator;

    /** @var AuditLogger */
    private $audit;

    /** @var Logger */
    private $logger;

    public function __construct(
        Database $db,
        SyncRegistry $registry,
        SyncScope $scope,
        ConflictService $conflicts,
        Validator $validator,
        AuditLogger $audit,
        Logger $logger
    ) {
        $this->db = $db;
        $this->registry = $registry;
        $this->scope = $scope;
        $this->conflicts = $conflicts;
        $this->validator = $validator;
        $this->audit = $audit;
        $this->logger = $logger;
    }

    /**
     * @return array{results: array[], server_time: string}
     */
    public function push(array $input, Request $request, AuthContext $auth): array
    {
        $deviceId = $auth->deviceId();
        if ($deviceId === null) {
            throw new HttpException(400, 'DEVICE_REQUIRED', 'La synchronisation nécessite une session ouverte depuis un appareil enregistré.');
        }
        $operations = $input['operations'] ?? null;
        if (!is_array($operations) || $operations === [] || count($operations) > self::MAX_OPERATIONS) {
            throw new ValidationException(['operations' => [sprintf('Liste de 1 à %d opérations attendue.', self::MAX_OPERATIONS)]]);
        }

        $results = [];
        $outcomes = [];
        foreach (array_values($operations) as $index => $raw) {
            try {
                $op = $this->validateOperation(is_array($raw) ? $raw : []);
            } catch (ValidationException $exception) {
                $results[] = [
                    'op_id' => is_array($raw) ? ($raw['op_id'] ?? null) : null,
                    'index' => $index,
                    'status' => self::REJECTED,
                    'duplicate' => false,
                    'error' => ['code' => 'INVALID_OPERATION', 'message' => $exception->getMessage(), 'errors' => $exception->getErrors()],
                ];
                continue;
            }
            $result = $this->process($op, $deviceId, $auth, $request, $outcomes);
            $outcomes[$op['op_id']] = $result['status'];
            $results[] = $result;
        }

        $this->touchDevice($deviceId);
        $counts = array_count_values(array_column($results, 'status'));
        $this->audit->record('SYNC_PUSH', $request, 'device', null, null, ['operations' => count($results), 'statuses' => $counts]);

        return ['results' => $results, 'server_time' => Clock::now()->format('Y-m-d\TH:i:s\Z')];
    }

    /**
     * @return array{changes: array[], next_cursor: int, has_more: bool, server_time: string}
     */
    public function pull(array $query, Request $request, AuthContext $auth): array
    {
        $data = $this->validator->validate($query, [
            'cursor' => 'nullable|integer|min:0',
            'limit' => 'nullable|integer|min:1|max:' . self::MAX_PULL_LIMIT,
        ]);
        $cursor = $data['cursor'] ?? 0;
        $limit = $data['limit'] ?? self::DEFAULT_PULL_LIMIT;
        list($scopeSql, $scopeParams) = $this->scope->patientIdsSql($auth);
        list($teamSql, $teamParams) = TeamService::teamIdsSql($auth->userId());

        $rows = $this->db->fetchAll(
            'SELECT seq, entity, entity_uuid, operation FROM sync_changes
             WHERE seq > ? AND (
                    user_id = ?
                 OR patient_id IN (' . $scopeSql . ')
                 OR team_id IN (' . $teamSql . ')
                 OR (patient_id IS NULL AND team_id IS NULL AND user_id IS NULL)
             )
             ORDER BY seq LIMIT ?',
            array_merge([$cursor, $auth->userId()], $scopeParams, $teamParams, [$limit + 1])
        );
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $nextCursor = $rows === [] ? $cursor : (int) $rows[count($rows) - 1]['seq'];

        // Un même élément modifié plusieurs fois n'est transmis qu'une fois, dans son état actuel.
        $latest = [];
        foreach ($rows as $row) {
            $key = $row['entity'] . '|' . $row['entity_uuid'];
            unset($latest[$key]);
            $latest[$key] = $row;
        }

        $changes = [];
        foreach ($latest as $row) {
            $handler = $this->registry->get((string) $row['entity']);
            if ($handler === null) {
                continue;
            }
            $change = ['seq' => (int) $row['seq'], 'entity' => (string) $row['entity'], 'id' => (string) $row['entity_uuid']];
            if ($row['operation'] === 'DELETE') {
                $changes[] = $change + ['operation' => 'DELETE', 'data' => null];
                continue;
            }
            $state = $handler->current((string) $row['entity_uuid'], $request);
            if ($state !== null) {
                $changes[] = $change + ['operation' => 'UPSERT', 'data' => $state];
            }
        }

        if ($auth->deviceId() !== null) {
            $this->touchDevice($auth->deviceId());
        }
        if ($changes !== []) {
            $this->audit->record('SYNC_PULL', $request, 'device', null, null, ['from' => $cursor, 'to' => $nextCursor, 'changes' => count($changes)]);
        }

        return [
            'changes' => $changes,
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'server_time' => Clock::now()->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    public function status(AuthContext $auth): array
    {
        $device = null;
        if ($auth->deviceId() !== null) {
            $row = $this->db->fetchOne('SELECT uuid, last_sync_at, revoked_at FROM devices WHERE id = ?', [$auth->deviceId()]);
            if ($row !== null) {
                $device = ['id' => (string) $row['uuid'], 'last_sync_at' => Clock::toIso($row['last_sync_at'])];
            }
        }
        return [
            'server_time' => Clock::now()->format('Y-m-d\TH:i:s\Z'),
            'latest_cursor' => (int) $this->db->fetchValue('SELECT COALESCE(MAX(seq), 0) FROM sync_changes'),
            'open_conflicts' => $this->conflicts->countOpen($auth->userId()),
            'device' => $device,
            'entities' => $this->registry->entities(),
        ];
    }

    private function process(array $op, int $deviceId, AuthContext $auth, Request $request, array $outcomes): array
    {
        $base = ['op_id' => $op['op_id'], 'entity' => $op['entity'], 'entity_id' => $op['entity_id'], 'duplicate' => false];

        $stored = $this->storedResult($deviceId, $op['op_id']);
        if ($stored !== null) {
            return ['duplicate' => true] + $stored;
        }

        if (isset($op['depends_on'])) {
            $parent = $outcomes[$op['depends_on']] ?? ($this->storedResult($deviceId, $op['depends_on'])['status'] ?? null);
            if ($parent !== self::APPLIED) {
                return $base + ['status' => self::DEFERRED, 'error' => [
                    'code' => 'DEPENDENCY_PENDING',
                    'message' => "L'opération dont celle-ci dépend n'a pas encore été appliquée.",
                    'errors' => new \stdClass(),
                ]];
            }
        }

        $handler = $this->registry->get($op['entity']);
        $isAction = $op['operation'] === 'ACTION';
        $supported = $handler !== null && ($isAction
            ? $handler instanceof SyncActionHandler && in_array($op['action'] ?? '', $handler->actions(), true)
            : $handler->supports($op['operation']));
        if (!$supported) {
            return $this->reject($deviceId, $auth, $op, $base, HttpException::conflict(
                sprintf(
                    'Opération %s non prise en charge pour « %s ».',
                    $isAction ? 'ACTION « ' . ($op['action'] ?? '') . ' »' : $op['operation'],
                    $op['entity']
                ),
                'UNSUPPORTED_OPERATION'
            ));
        }

        try {
            return $this->db->transaction(function () use ($handler, $op, $base, $deviceId, $auth, $request): array {
                $conflicts = [];
                $status = self::APPLIED;
                switch ($op['operation']) {
                    case 'CREATE':
                        $data = $handler->create($op['entity_id'], $op['payload'] ?? [], $request);
                        break;
                    case 'UPDATE':
                        list($status, $data, $conflicts) = $this->merge($handler, $op, $request);
                        break;
                    case 'ACTION':
                        /** @var SyncActionHandler $handler */
                        $data = $handler->action($op['entity_id'], (string) $op['action'], $op['payload'] ?? [], $request);
                        break;
                    default:
                        $handler->delete($op['entity_id'], $request);
                        $data = null;
                }

                $conflictUuid = $conflicts !== [] ? Uuid::v4() : null;
                $result = $base + [
                    'status' => $status,
                    'version' => is_array($data) && isset($data['version']) ? (int) $data['version'] : null,
                    'data' => $data,
                    'conflict' => $conflictUuid === null ? null : ['id' => $conflictUuid, 'fields' => array_keys($conflicts), 'details' => $conflicts],
                    'error' => null,
                ];
                $operationId = $this->store($deviceId, $auth, $op, $status, $result);
                if ($conflictUuid !== null) {
                    $this->conflicts->record($conflictUuid, $operationId, $auth, $op, $conflicts, $data);
                }
                return $result;
            });
        } catch (HttpException $exception) {
            if ($exception->getStatus() >= 500) {
                return $this->transientError($base, $exception);
            }
            return $this->reject($deviceId, $auth, $op, $base, $exception);
        } catch (\PDOException $exception) {
            $stored = self::isDuplicateOperation($exception) ? $this->storedResult($deviceId, $op['op_id']) : null;
            if ($stored !== null) {
                return ['duplicate' => true] + $stored;
            }
            return $this->transientError($base, $exception);
        } catch (\Throwable $exception) {
            return $this->transientError($base, $exception);
        }
    }

    /**
     * @return array{0: string, 1: array, 2: array} Statut, état résultant, conflits par champ
     */
    private function merge(SyncEntityHandler $handler, array $op, Request $request): array
    {
        $uuid = $op['entity_id'];
        $current = $handler->current($uuid, $request);
        if ($current === null) {
            throw HttpException::notFound('Élément introuvable ou inaccessible.');
        }
        $payload = array_diff_key($op['payload'] ?? [], array_flip(self::SERVER_FIELDS));
        if ($payload === []) {
            throw new ValidationException(['payload' => ['Aucun champ à modifier.']]);
        }
        $base = $op['base'] ?? [];
        $upToDate = isset($op['base_version']) && $op['base_version'] === (int) $current['version'];

        $apply = [];
        $conflicts = [];
        foreach ($payload as $field => $value) {
            if ($upToDate || !array_key_exists($field, $current)) {
                $apply[$field] = $value;
            } elseif (self::sameValue($current[$field], $value)) {
                continue;
            } elseif (array_key_exists($field, $base) && self::sameValue($current[$field], $base[$field])) {
                $apply[$field] = $value;
            } else {
                $conflicts[$field] = ['base' => $base[$field] ?? null, 'client' => $value, 'server' => $current[$field]];
            }
        }

        $data = $apply === [] ? $current : $handler->update($uuid, $apply, $request);
        return [$conflicts === [] ? self::APPLIED : self::CONFLICT, $data, $conflicts];
    }

    private function reject(int $deviceId, AuthContext $auth, array $op, array $base, HttpException $exception): array
    {
        $result = $base + [
            'status' => self::REJECTED,
            'version' => null,
            'data' => null,
            'conflict' => null,
            'error' => [
                'code' => $exception->getErrorCode(),
                'message' => $exception->getMessage(),
                'errors' => $exception->getErrors() === [] ? new \stdClass() : $exception->getErrors(),
            ],
        ];
        try {
            $this->store($deviceId, $auth, $op, self::REJECTED, $result);
        } catch (\PDOException $duplicate) {
            $stored = self::isDuplicateOperation($duplicate) ? $this->storedResult($deviceId, $op['op_id']) : null;
            if ($stored !== null) {
                return ['duplicate' => true] + $stored;
            }
            throw $duplicate;
        }
        return $result;
    }

    /**
     * Erreur technique : rien n'est enregistré, l'appareil renverra l'opération plus tard.
     */
    private function transientError(array $base, \Throwable $exception): array
    {
        $this->logger->error('Échec technique d\'une opération de synchronisation', [
            'op_id' => $base['op_id'],
            'entity' => $base['entity'],
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'location' => $exception->getFile() . ':' . $exception->getLine(),
        ]);
        return $base + ['status' => self::ERROR, 'error' => [
            'code' => 'SERVER_ERROR',
            'message' => 'Erreur temporaire du serveur : l\'opération sera renvoyée.',
            'errors' => new \stdClass(),
        ]];
    }

    private function store(int $deviceId, AuthContext $auth, array $op, string $status, array $result): int
    {
        unset($result['duplicate']);
        return $this->db->insert('sync_operations', [
            'device_id' => $deviceId,
            'op_id' => $op['op_id'],
            'user_id' => $auth->userId(),
            'entity' => $op['entity'],
            'entity_uuid' => $op['entity_id'],
            'operation' => $op['operation'],
            'action_name' => $op['action'] ?? null,
            'base_version' => $op['base_version'] ?? null,
            'status' => $status,
            'result' => (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
            'client_created_at' => $op['client_created_at'] ?? null,
            'received_at' => Clock::nowForDatabase(),
        ]);
    }

    private function storedResult(int $deviceId, string $opId): ?array
    {
        $json = $this->db->fetchValue('SELECT result FROM sync_operations WHERE device_id = ? AND op_id = ?', [$deviceId, $opId]);
        if ($json === null) {
            return null;
        }
        $result = json_decode((string) $json, true);
        return is_array($result) ? $result : null;
    }

    private function validateOperation(array $raw): array
    {
        return $this->validator->validate($raw, [
            'op_id' => 'required|uuid',
            'entity' => ['required', 'string', 'max:50', 'regex:/^[a-z_]+$/'],
            'entity_id' => 'required|uuid',
            'operation' => 'required|in:CREATE,UPDATE,DELETE,ACTION',
            'action' => 'nullable|string|max:50',
            'base_version' => 'nullable|integer|min:0',
            'base' => 'nullable|array',
            'payload' => 'nullable|array',
            'depends_on' => 'nullable|uuid',
            'client_created_at' => 'nullable|datetime',
        ]);
    }

    private function touchDevice(int $deviceId): void
    {
        $now = Clock::nowForDatabase();
        $this->db->update('devices', ['last_sync_at' => $now, 'last_seen_at' => $now, 'updated_at' => $now], 'id = ?', [$deviceId]);
    }

    /**
     * @param mixed $a
     * @param mixed $b
     */
    private static function sameValue($a, $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 1e-9;
        }
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        return (string) $a === (string) $b;
    }

    private static function isDuplicateOperation(\PDOException $exception): bool
    {
        return strpos($exception->getMessage(), 'uq_sync_operations_op') !== false;
    }
}
