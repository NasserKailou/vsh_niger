<?php

declare(strict_types=1);

namespace Vsh\Modules\Appointments;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncActionHandler;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Rendez-vous : planning consultable hors ligne ; demande et annulation mises en file par le patient
 * (le créneau est revérifié à la réception : un créneau devenu complet est refusé SLOT_FULL).
 */
final class AppointmentSyncHandler implements SyncEntityHandler, SyncActionHandler, PatientScopedEntity
{
    /** @var Database */
    private $db;

    /** @var AppointmentService */
    private $service;

    public function __construct(Database $db, AppointmentService $service)
    {
        $this->db = $db;
        $this->service = $service;
    }

    public function entity(): string
    {
        return 'appointment';
    }

    public function supports(string $operation): bool
    {
        return $operation === 'CREATE';
    }

    public function actions(): array
    {
        return ['confirm', 'reschedule', 'cancel', 'check_in', 'no_show'];
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM appointments WHERE patient_id = ? AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $auth = self::auth($request);
        $row = $this->db->fetchOne('SELECT * FROM appointments WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            return null;
        }
        if (!$this->service->canSee($auth, $row)) {
            // Rendez-vous retiré de ce planning (praticien changé) : statut seul, pour le masquer localement.
            return $auth->isPatient() ? null : ['id' => $uuid, 'status' => (string) $row['status'], 'available' => false];
        }
        return $this->service->present($row, $auth->isPatient());
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        if ($this->db->fetchValue('SELECT id FROM appointments WHERE uuid = ?', [$uuid]) !== null) {
            $existing = $this->current($uuid, $request);
            if ($existing === null || isset($existing['available'])) {
                throw HttpException::conflict('Ce rendez-vous existe déjà.', 'ALREADY_EXISTS');
            }
            return $existing;
        }
        return $this->service->create($payload, $request, $uuid);
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        throw new \LogicException('Un rendez-vous évolue par actions.');
    }

    public function action(string $uuid, string $action, array $payload, Request $request): array
    {
        switch ($action) {
            case 'confirm':
                return $this->service->confirm($uuid, $payload, $request);
            case 'reschedule':
                return $this->service->reschedule($uuid, $payload, $request);
            case 'check_in':
                return $this->service->checkIn($uuid, $request);
            case 'no_show':
                return $this->service->noShow($uuid, $request);
            default:
                return $this->service->cancel($uuid, $payload, $request);
        }
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Un rendez-vous ne se supprime pas : il s\'annule.');
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
