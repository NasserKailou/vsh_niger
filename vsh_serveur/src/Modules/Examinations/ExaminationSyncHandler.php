<?php

declare(strict_types=1);

namespace Vsh\Modules\Examinations;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncActionHandler;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Examens : prescription hors ligne, puis actions de la machine à états
 * (start, record_results, complete, validate, cancel).
 */
final class ExaminationSyncHandler implements SyncEntityHandler, SyncActionHandler, PatientScopedEntity
{
    /** @var Database */
    private $db;

    /** @var ExaminationService */
    private $service;

    public function __construct(Database $db, ExaminationService $service)
    {
        $this->db = $db;
        $this->service = $service;
    }

    public function entity(): string
    {
        return 'examination';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'CREATE';
    }

    public function actions(): array
    {
        return ['start', 'record_results', 'complete', 'validate', 'cancel'];
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM examinations WHERE patient_id = ? AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $auth = self::auth($request);
        $row = $this->db->fetchOne('SELECT * FROM examinations WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            return null;
        }
        if ($auth->isPatient()) {
            // Côté patient, seuls les examens validés sont transmis (avec leurs résultats).
            return $row['status'] === 'VALIDE' ? $this->service->present($row, $auth, true) : null;
        }
        if (!$auth->can('examinations.read')) {
            return null;
        }
        return $this->service->present($row, $auth, true);
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if ($this->db->fetchValue('SELECT id FROM examinations WHERE uuid = ?', [$uuid]) !== null) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Cet examen existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        return $this->service->prescribe($payload, $request, $uuid);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        throw new \LogicException('Un examen évolue par actions, pas par modification directe.');
    }

    public function action(string $uuid, string $action, array $payload, Request $request): array
    {
        switch ($action) {
            case 'start':
                return $this->service->start($uuid, $request);
            case 'record_results':
                return $this->service->recordResults($uuid, $payload, $request);
            case 'complete':
                return $this->service->complete($uuid, $request);
            case 'validate':
                return $this->service->validate($uuid, $request);
            default:
                return $this->service->cancel($uuid, $payload, $request);
        }
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Un examen ne se supprime pas : il s\'annule.');
    }

    private static function auth(Request $request): AuthContext
    {
        $auth = $request->attribute('auth');
        if (!$auth instanceof AuthContext) {
            throw HttpException::unauthorized();
        }
        return $auth;
    }
}
