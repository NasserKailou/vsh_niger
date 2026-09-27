<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Settings\SettingsService;

/**
 * Tarifs datés : aucun prix n'est codé en dur. Un tarif couvre une période [valid_from ; valid_to]
 * (dates locales de la clinique, valid_to vide = sans fin). Les périodes d'un même élément ne se
 * chevauchent jamais, et un tarif déjà appliqué ne change plus de montant : l'historique reste exact.
 */
final class TariffService
{
    /** Type facturable => référentiel correspondant */
    public const BILLABLE_TYPES = [
        'MEDICAL_ACT' => 'medical-acts',
        'TREATMENT_TYPE' => 'treatment-types',
        'EXAMINATION_TYPE' => 'examination-types',
        'MEDICATION' => 'medications',
    ];

    private const OPEN_END = '9999-12-31';

    /** @var Database */
    private $db;

    /** @var ReferenceService */
    private $references;

    /** @var SettingsService */
    private $settings;

    /** @var Validator */
    private $validator;

    /** @var AuditLogger */
    private $audit;

    public function __construct(
        Database $db,
        ReferenceService $references,
        SettingsService $settings,
        Validator $validator,
        AuditLogger $audit
    ) {
        $this->db = $db;
        $this->references = $references;
        $this->settings = $settings;
        $this->validator = $validator;
        $this->audit = $audit;
    }

    public function list(array $query): array
    {
        $filters = $this->validator->validate($query, [
            'billable_type' => 'nullable|in:' . implode(',', array_keys(self::BILLABLE_TYPES)),
            'billable_id' => 'nullable|uuid',
        ]);
        $where = ['t.deleted_at IS NULL'];
        $params = [];
        if (isset($filters['billable_type'])) {
            $where[] = 't.billable_type = ?';
            $params[] = $filters['billable_type'];
            if (isset($filters['billable_id'])) {
                $where[] = 't.billable_id = ?';
                $params[] = $this->resolveItem($filters['billable_type'], $filters['billable_id']);
            }
        }
        $rows = $this->db->fetchAll(
            self::selectSql() . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY billable_label, t.valid_from DESC LIMIT 1000',
            $params
        );
        return array_map([$this, 'present'], $rows);
    }

    public function create(array $input, Request $request): array
    {
        $data = $this->validator->validate($input, [
            'billable_type' => 'required|in:' . implode(',', array_keys(self::BILLABLE_TYPES)),
            'billable_id' => 'required|uuid',
            'amount' => 'required|integer|min:0|max:100000000',
            'valid_from' => 'required|date',
            'valid_to' => 'nullable|date',
        ]);
        $itemId = $this->resolveItem($data['billable_type'], $data['billable_id']);
        $validTo = $data['valid_to'] ?? null;
        if ($data['valid_from'] < $this->today()) {
            throw new ValidationException(['valid_from' => ['Un nouveau tarif ne peut pas commencer dans le passé.']]);
        }
        $this->assertPeriod($data['valid_from'], $validTo);

        return $this->db->transaction(function () use ($data, $itemId, $validTo, $request): array {
            // Le tarif en cours sans date de fin est clôturé la veille du nouveau tarif.
            $previousEnd = (new \DateTimeImmutable($data['valid_from']))->modify('-1 day')->format('Y-m-d');
            $this->db->execute(
                'UPDATE tariffs SET valid_to = ?, updated_at = ?, version = version + 1
                 WHERE billable_type = ? AND billable_id = ? AND deleted_at IS NULL AND valid_to IS NULL AND valid_from < ?',
                [$previousEnd, Clock::nowForDatabase(), $data['billable_type'], $itemId, $data['valid_from']]
            );
            $this->assertNoOverlap($data['billable_type'], $itemId, $data['valid_from'], $validTo, null);

            $uuid = Uuid::v4();
            $now = Clock::nowForDatabase();
            $this->db->insert('tariffs', [
                'uuid' => $uuid,
                'billable_type' => $data['billable_type'],
                'billable_id' => $itemId,
                'amount' => $data['amount'],
                'currency' => (string) $this->settings->get('app.currency', 'XOF'),
                'valid_from' => $data['valid_from'],
                'valid_to' => $validTo,
                'created_by' => self::actorId($request),
                'updated_by' => self::actorId($request),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $tariff = $this->find($uuid);
            $this->audit->record('TARIFF_CREATED', $request, 'tariff', $uuid, null, $tariff);
            return $tariff;
        });
    }

    public function update(string $uuid, array $input, Request $request): array
    {
        $before = $this->find($uuid);
        $data = $this->validator->validate($input, [
            'amount' => 'integer|min:0|max:100000000',
            'valid_from' => 'date',
            'valid_to' => 'nullable|date',
        ]);
        $today = $this->today();

        if ($before['status'] === 'PAST') {
            throw HttpException::conflict('Un tarif échu ne peut plus être modifié.', 'TARIFF_CLOSED');
        }
        if ($before['status'] === 'CURRENT' && (isset($data['amount']) || isset($data['valid_from']))) {
            throw HttpException::conflict(
                'Ce tarif est déjà appliqué : son montant et sa date de début ne changent plus. Créez un nouveau tarif à partir de la date souhaitée.',
                'TARIFF_IN_EFFECT'
            );
        }
        $validFrom = $data['valid_from'] ?? $before['valid_from'];
        $validTo = array_key_exists('valid_to', $data) ? $data['valid_to'] : $before['valid_to'];
        if (isset($data['valid_from']) && $validFrom < $today) {
            throw new ValidationException(['valid_from' => ['La date de début ne peut pas être dans le passé.']]);
        }
        if ($validTo !== null && $validTo < $today) {
            throw new ValidationException(['valid_to' => ['La date de fin ne peut pas être dans le passé.']]);
        }
        $this->assertPeriod($validFrom, $validTo);

        return $this->db->transaction(function () use ($uuid, $before, $data, $validFrom, $validTo, $request): array {
            $row = (array) $this->db->fetchOne('SELECT id, billable_type, billable_id FROM tariffs WHERE uuid = ? FOR UPDATE', [$uuid]);
            $this->assertNoOverlap((string) $row['billable_type'], (int) $row['billable_id'], $validFrom, $validTo, (int) $row['id']);
            $changes = ['valid_from' => $validFrom, 'valid_to' => $validTo, 'updated_by' => self::actorId($request), 'updated_at' => Clock::nowForDatabase()];
            if (isset($data['amount'])) {
                $changes['amount'] = $data['amount'];
            }
            $this->db->update('tariffs', $changes, 'id = ?', [(int) $row['id']]);
            $this->db->execute('UPDATE tariffs SET version = version + 1 WHERE id = ?', [(int) $row['id']]);

            $after = $this->find($uuid);
            list($old, $new) = AuditLogger::changes($before, $after);
            $this->audit->record('TARIFF_UPDATED', $request, 'tariff', $uuid, $old, $new);
            return $after;
        });
    }

    public function current(array $query): ?array
    {
        $data = $this->validator->validate($query, [
            'billable_type' => 'required|in:' . implode(',', array_keys(self::BILLABLE_TYPES)),
            'billable_id' => 'required|uuid',
            'date' => 'nullable|date',
        ]);
        $itemId = $this->resolveItem($data['billable_type'], $data['billable_id']);
        $row = $this->priceAt($data['billable_type'], $itemId, $data['date'] ?? $this->today());
        return $row === null ? null : $this->present($row);
    }

    /**
     * Tarif applicable à une date (utilisé par la facturation).
     */
    public function priceAt(string $billableType, int $itemId, string $date): ?array
    {
        return $this->db->fetchOne(
            self::selectSql() . ' WHERE t.deleted_at IS NULL AND t.billable_type = ? AND t.billable_id = ?
               AND t.valid_from <= ? AND (t.valid_to IS NULL OR t.valid_to >= ?)
             ORDER BY t.valid_from DESC LIMIT 1',
            [$billableType, $itemId, $date, $date]
        );
    }

    /**
     * Tarifs modifiés depuis une date (paquet de référence de l'application mobile).
     */
    public function changedSince(?string $since): array
    {
        $today = $this->today();
        $sql = self::selectSql() . ' WHERE t.deleted_at IS NULL AND (t.valid_to IS NULL OR t.valid_to >= ?)';
        $params = [$today];
        if ($since !== null) {
            $sql .= ' AND t.updated_at >= ?';
            $params[] = $since;
        }
        return array_map([$this, 'present'], $this->db->fetchAll($sql . ' ORDER BY t.id', $params));
    }

    /**
     * Date du jour dans le fuseau de la clinique (les tarifs sont exprimés en dates locales).
     */
    public function today(): string
    {
        $timezone = new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
        return Clock::now()->setTimezone($timezone)->format('Y-m-d');
    }

    public function present(array $row): array
    {
        $today = $this->today();
        $validTo = $row['valid_to'] !== null ? (string) $row['valid_to'] : null;
        if ((string) $row['valid_from'] > $today) {
            $status = 'FUTURE';
        } elseif ($validTo !== null && $validTo < $today) {
            $status = 'PAST';
        } else {
            $status = 'CURRENT';
        }
        return [
            'id' => (string) $row['uuid'],
            'billable_type' => (string) $row['billable_type'],
            'billable_id' => $row['billable_uuid'] !== null ? (string) $row['billable_uuid'] : null,
            'billable_label' => $row['billable_label'] !== null ? (string) $row['billable_label'] : null,
            'amount' => (int) $row['amount'],
            'currency' => (string) $row['currency'],
            'valid_from' => (string) $row['valid_from'],
            'valid_to' => $validTo,
            'status' => $status,
            'version' => (int) $row['version'],
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
    }

    private function find(string $uuid): array
    {
        $row = $this->db->fetchOne(self::selectSql() . ' WHERE t.uuid = ? AND t.deleted_at IS NULL', [$uuid]);
        if ($row === null) {
            throw HttpException::notFound('Tarif introuvable.');
        }
        return $this->present($row);
    }

    private function resolveItem(string $billableType, string $itemUuid): int
    {
        $id = $this->references->idFor(self::BILLABLE_TYPES[$billableType], $itemUuid);
        if ($id === null) {
            throw new ValidationException(['billable_id' => ['Élément facturable introuvable.']]);
        }
        return $id;
    }

    private function assertPeriod(string $validFrom, ?string $validTo): void
    {
        if ($validTo !== null && $validTo < $validFrom) {
            throw new ValidationException(['valid_to' => ['La date de fin doit être postérieure ou égale à la date de début.']]);
        }
    }

    private function assertNoOverlap(string $billableType, int $itemId, string $validFrom, ?string $validTo, ?int $exceptId): void
    {
        $overlap = $this->db->fetchValue(
            'SELECT uuid FROM tariffs
             WHERE billable_type = ? AND billable_id = ? AND deleted_at IS NULL AND id <> ?
               AND valid_from <= ? AND COALESCE(valid_to, ?) >= ?
             LIMIT 1',
            [$billableType, $itemId, $exceptId ?? 0, $validTo ?? self::OPEN_END, self::OPEN_END, $validFrom]
        );
        if ($overlap !== null) {
            throw HttpException::conflict('Cette période chevauche un autre tarif du même élément.', 'TARIFF_OVERLAP');
        }
    }

    private static function selectSql(): string
    {
        return "SELECT t.*,
                    COALESCE(ma.uuid, tt.uuid, et.uuid, m.uuid) AS billable_uuid,
                    COALESCE(ma.label, tt.label, et.label, TRIM(CONCAT(m.dci, ' ', COALESCE(m.strength, '')))) AS billable_label
                FROM tariffs t
                LEFT JOIN medical_acts ma ON t.billable_type = 'MEDICAL_ACT' AND ma.id = t.billable_id
                LEFT JOIN treatment_types tt ON t.billable_type = 'TREATMENT_TYPE' AND tt.id = t.billable_id
                LEFT JOIN examination_types et ON t.billable_type = 'EXAMINATION_TYPE' AND et.id = t.billable_id
                LEFT JOIN medications m ON t.billable_type = 'MEDICATION' AND m.id = t.billable_id";
    }

    private static function actorId(Request $request): ?int
    {
        $auth = $request->attribute('auth');
        return $auth instanceof AuthContext ? $auth->userId() : null;
    }
}
