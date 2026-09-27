<?php

declare(strict_types=1);

namespace Vsh\Modules\Billing;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuthContext;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Sync\PatientScopedEntity;
use Vsh\Modules\Sync\SyncEntityHandler;

/**
 * Factures en LECTURE SEULE hors ligne (consultation par le patient et le personnel habilité).
 * La facturation elle-même se fait en ligne : numérotation, tarifs et émission relèvent du serveur.
 */
final class InvoiceSyncHandler implements SyncEntityHandler, PatientScopedEntity
{
    /** @var Database */
    private $db;

    /** @var InvoiceService */
    private $service;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    public function __construct(Database $db, InvoiceService $service, PatientRepository $patients, PatientPolicy $policy)
    {
        $this->db = $db;
        $this->service = $service;
        $this->patients = $patients;
        $this->policy = $policy;
    }

    public function entity(): string
    {
        return 'invoice';
    }

    public function supports(string $operation): bool
    {
        return false;
    }

    public function snapshotSql(): string
    {
        return 'SELECT uuid FROM invoices WHERE patient_id = ? AND number IS NOT NULL AND deleted_at IS NULL';
    }

    public function current(string $uuid, Request $request): ?array
    {
        $auth = self::auth($request);
        $row = $this->db->fetchOne('SELECT * FROM invoices WHERE uuid = ? AND number IS NOT NULL AND deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            return null;
        }
        if ($auth->isPatient()) {
            $patient = (array) $this->patients->findById((int) $row['patient_id']);
            if (!$auth->can('self.invoices.read') || !$this->policy->owns($auth, $patient)) {
                return null;
            }
            return $this->service->present($row, true, false);
        }
        return $auth->can('invoices.read') ? $this->service->present($row, true, false) : null;
    }

    public function create(string $uuid, array $payload, Request $request): array
    {
        throw new \LogicException('Les factures se créent en ligne.');
    }

    public function update(string $uuid, array $fields, Request $request): array
    {
        throw new \LogicException('Les factures se modifient en ligne.');
    }

    public function delete(string $uuid, Request $request): void
    {
        throw new \LogicException('Les factures ne se suppriment pas.');
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
