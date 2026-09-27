<?php

declare(strict_types=1);

namespace Vsh\Modules\Prescriptions;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncActionHandler;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Ordonnances : rédaction et modification du brouillon hors ligne, actions « sign » et « cancel ».
 * Les lignes font partie de l'ordonnance (champ `items`, remplacé en bloc).
 */
final class PrescriptionSyncHandler implements SyncEntityHandler, SyncActionHandler, PatientScopedEntity
{
    /** @var Database */
    private $db;

    /** @var PrescriptionService */
    private $service;

    public function __construct(Database $db, PrescriptionService $service)
    {
        $this->db = $db;
        $this->service = $service;
    }

    public function entity(): string
    {
        return 'prescription';
    }

    public function supports(string $operation): bool
    {
        return in_array($operation, ['CREATE', 'UPDATE'], true);
    }

    public function actions(): array
    {
        return ['sign', 'cancel'];
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM prescriptions WHERE patient_id = ? AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $auth = self::auth($request);
        $row = $this->db->fetchOne('SELECT * FROM prescriptions WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            return null;
        }
        if ($auth->isPatient()) {
            // Côté patient, seules les ordonnances signées sont transmises.
            return $row['status'] === 'SIGNEE' ? $this->service->present($row, $auth, true) : null;
        }
        if (!$auth->can('prescriptions.read')) {
            return null;
        }
        return $this->service->present($row, $auth, true);
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if ($this->db->fetchValue('SELECT id FROM prescriptions WHERE uuid = ?', [$uuid]) !== null) {
            $existing = $this->current($uuid, $request);
            if ($existing === null) {
                throw HttpException::conflict('Cette ordonnance existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        return $this->service->create($payload, $request, $uuid);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        return $this->service->update($uuid, $fields, $request);
    }

    public function action(string $uuid, string $action, array $payload, Request $request): array
    {
        return $action === 'sign'
            ? $this->service->sign($uuid, $request)
            : $this->service->cancel($uuid, $payload, $request);
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Une ordonnance ne se supprime pas : elle s\'annule.');
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
