<?php

declare(strict_types=1);

namespace Vsh\Modules\Billing;

use Vsh\Core\Database;
use Vsh\Core\Exceptions\HttpException;
use Vsh\Core\Exceptions\ValidationException;
use Vsh\Core\Http\Pagination;
use Vsh\Core\Http\Request;
use Vsh\Core\Security\AuditLogger;
use Vsh\Core\Security\AuthContext;
use Vsh\Core\Support\Clock;
use Vsh\Core\Support\SequenceGenerator;
use Vsh\Core\Support\Uuid;
use Vsh\Core\Sync\ChangeJournal;
use Vsh\Core\Validation\Validator;
use Vsh\Modules\Notifications\NotificationService;
use Vsh\Modules\Patients\PatientPolicy;
use Vsh\Modules\Patients\PatientRepository;
use Vsh\Modules\Reference\TariffService;
use Vsh\Modules\Settings\DocumentBranding;
use Vsh\Modules\Settings\SettingsService;

/**
 * Facturation (D-004 : les paiements se font HORS de la plateforme).
 *
 *   BROUILLON ──issue──▶ EMISE (n° définitif, visible par le patient) ──cancel (motif)──▶ ANNULEE
 *   État de règlement DÉCLARATIF sur une facture émise : NON_REGLEE / PARTIELLEMENT_REGLEE / REGLEE.
 *
 * - Aucun prix n'est codé : chaque ligne copie le tarif en vigueur à la date de l'acte (référentiel daté).
 *   Seule une ligne « OTHER » accepte un prix saisi par une personne habilitée.
 * - Un soin, un examen ne peut être facturé qu'une fois (hors factures annulées).
 * - L'émission d'une facture liée à une visite à domicile terminée la passe à FACTUREE.
 */
final class InvoiceService
{
    /** Lignes saisies manuellement : type → table du référentiel, type de tarif, colonne du libellé. */
    private const MANUAL_REFERENCES = [
        'MEDICAL_ACT' => ['medical_acts', 'MEDICAL_ACT', 'label'],
        'MEDICATION' => ['medications', 'MEDICATION', 'dci'],
    ];

    private const MAX_AMOUNT = 1000000000;

    /** @var Database */
    private $db;

    /** @var PatientRepository */
    private $patients;

    /** @var PatientPolicy */
    private $policy;

    /** @var TariffService */
    private $tariffs;

    /** @var SettingsService */
    private $settings;

    /** @var SequenceGenerator */
    private $sequences;

    /** @var NotificationService */
    private $notifications;

    /** @var ChangeJournal */
    private $journal;

    /** @var AuditLogger */
    private $audit;

    /** @var Validator */
    private $validator;

    public function __construct(
        Database $db,
        PatientRepository $patients,
        PatientPolicy $policy,
        TariffService $tariffs,
        SettingsService $settings,
        SequenceGenerator $sequences,
        NotificationService $notifications,
        ChangeJournal $journal,
        AuditLogger $audit,
        Validator $validator,
        InvoicePdf $pdf
    ) {
        $this->db = $db;
        $this->patients = $patients;
        $this->policy = $policy;
        $this->tariffs = $tariffs;
        $this->settings = $settings;
        $this->sequences = $sequences;
        $this->notifications = $notifications;
        $this->journal = $journal;
        $this->audit = $audit;
        $this->validator = $validator;
        $this->pdf = $pdf;
    }

    /** @var InvoicePdf */
    private $pdf;

    // ------------------------------------------------------------------ Document PDF

    /**
     * Facture au format PDF (personnel). Un brouillon est marqué provisoire. Export tracé dans l'audit.
     *
     * @return array{filename: string, content: string}
     */
    public function pdf(string $uuid, Request $request): array
    {
        $invoice = $this->findOrFail($uuid);
        $this->audit->record('INVOICE_EXPORTED', $request, 'invoice', $uuid, null, ['format' => 'pdf', 'status' => $invoice['status']]);
        return $this->document($invoice);
    }

    /**
     * Facture PDF d'un dossier rattaché au compte patient : émise ou annulée après émission, jamais un brouillon.
     *
     * @return array{filename: string, content: string}
     */
    public function pdfForOwnPatient(string $patientUuid, string $invoiceUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $invoice = $this->db->fetchOne(
            "SELECT * FROM invoices WHERE uuid = ? AND patient_id = ? AND number IS NOT NULL AND status <> 'BROUILLON' AND deleted_at IS NULL",
            [$invoiceUuid, (int) $patient['id']]
        );
        if ($invoice === null) {
            throw HttpException::notFound('Facture introuvable.');
        }
        $this->audit->record('INVOICE_EXPORTED', $request, 'invoice', $invoiceUuid, null, ['format' => 'pdf', 'by' => 'patient']);
        return $this->document($invoice);
    }

    private function document(array $invoice): array
    {
        $items = $this->db->fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? AND deleted_at IS NULL ORDER BY sort_order, id', [(int) $invoice['id']]);
        $patient = (array) $this->patients->findById((int) $invoice['patient_id']);
        $name = $invoice['number'] !== null ? (string) $invoice['number'] : 'facture-brouillon-' . substr((string) $invoice['uuid'], 0, 8);
        return ['filename' => $name . '.pdf', 'content' => $this->pdf->render($invoice, $items, $patient)];
    }

    // ------------------------------------------------------------------ Lecture

    /**
     * @return array{0: array[], 1: int}
     */
    public function list(array $query, Pagination $pagination): array
    {
        $data = $this->validator->validate($query, [
            'patient_id' => 'nullable|uuid',
            'status' => 'nullable|in:BROUILLON,EMISE,ANNULEE',
            'settlement_status' => 'nullable|in:NON_REGLEE,PARTIELLEMENT_REGLEE,REGLEE',
            'number' => 'nullable|string|max:30',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);
        list($where, $params) = $this->filters($data);
        $sql = ' FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE ' . implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*)' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT i.*' . $sql . ' ORDER BY i.created_at DESC, i.id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$pagination->perPage(), $pagination->offset()])
        );
        return [array_map(function (array $row): array {
            return $this->present($row, false, false);
        }, $rows), $total];
    }

    /**
     * Suivi des impayés (tableau de bord) : factures émises sur la période, montants nets et déclarés.
     */
    public function summary(array $query): array
    {
        $data = $this->validator->validate($query, ['from' => 'nullable|date', 'to' => 'nullable|date']);
        list($where, $params) = $this->filters($data + ['status' => 'EMISE']);
        $row = (array) $this->db->fetchOne(
            "SELECT COUNT(*) AS invoices, COALESCE(SUM(i.net_amount), 0) AS net, COALESCE(SUM(i.declared_paid_amount), 0) AS declared,
                    COALESCE(SUM(i.settlement_status = 'NON_REGLEE'), 0) AS unpaid,
                    COALESCE(SUM(i.settlement_status = 'PARTIELLEMENT_REGLEE'), 0) AS partial,
                    COALESCE(SUM(i.settlement_status = 'REGLEE'), 0) AS paid
             FROM invoices i JOIN patients p ON p.id = i.patient_id WHERE " . implode(' AND ', $where),
            $params
        );
        return [
            'currency' => $this->currency(),
            'from' => $data['from'] ?? null,
            'to' => $data['to'] ?? null,
            'issued_count' => (int) $row['invoices'],
            'net_amount' => (int) $row['net'],
            'declared_paid_amount' => (int) $row['declared'],
            'outstanding_amount' => (int) $row['net'] - (int) $row['declared'],
            'by_settlement' => [
                'NON_REGLEE' => (int) $row['unpaid'],
                'PARTIELLEMENT_REGLEE' => (int) $row['partial'],
                'REGLEE' => (int) $row['paid'],
            ],
        ];
    }

    public function get(string $uuid): array
    {
        return $this->present($this->findOrFail($uuid), true, true);
    }

    /**
     * Factures émises (et celles annulées après émission) d'un dossier rattaché au compte patient.
     */
    public function forOwnPatient(string $patientUuid, Request $request): array
    {
        $auth = self::auth($request);
        $patient = $this->patients->findByUuid($patientUuid);
        if ($patient === null || !$this->policy->owns($auth, $patient)) {
            throw HttpException::notFound('Dossier introuvable.');
        }
        $rows = $this->db->fetchAll(
            "SELECT * FROM invoices WHERE patient_id = ? AND number IS NOT NULL AND status <> 'BROUILLON' AND deleted_at IS NULL ORDER BY issued_at DESC",
            [(int) $patient['id']]
        );
        return array_map(function (array $row): array {
            return $this->present($row, true, false);
        }, $rows);
    }

    // ------------------------------------------------------------------ Brouillon

    /**
     * Crée un brouillon. Avec `consultation_id` ou `homecare_request_id`, les soins réalisés et examens
     * effectués non encore facturés sont ajoutés automatiquement au tarif en vigueur à leur date.
     */
    public function create(array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'patient_id' => 'required|uuid',
            'consultation_id' => 'nullable|uuid',
            'homecare_request_id' => 'nullable|uuid',
            'notes' => 'nullable|string|max:500',
            'items' => 'nullable|array|max:100',
        ]);
        $patient = $this->patients->findByUuid($data['patient_id']);
        if ($patient === null) {
            throw new ValidationException(['patient_id' => ['Patient introuvable.']]);
        }
        $consultation = null;
        if (isset($data['consultation_id'])) {
            $consultation = $this->db->fetchOne('SELECT * FROM consultations WHERE uuid = ? AND deleted_at IS NULL', [$data['consultation_id']]);
            if ($consultation === null || (int) $consultation['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(['consultation_id' => ['Consultation introuvable pour ce patient.']]);
            }
        }
        $homecare = null;
        if (isset($data['homecare_request_id'])) {
            $homecare = $this->db->fetchOne('SELECT * FROM homecare_requests WHERE uuid = ? AND deleted_at IS NULL', [$data['homecare_request_id']]);
            if ($homecare === null || (int) $homecare['patient_id'] !== (int) $patient['id']) {
                throw new ValidationException(['homecare_request_id' => ['Visite à domicile introuvable pour ce patient.']]);
            }
            if ($homecare['status'] !== 'TERMINEE') {
                throw HttpException::conflict('Seule une visite à domicile terminée peut être facturée.', 'INVALID_TRANSITION');
            }
        }
        $manual = isset($data['items']) ? $this->manualItems((array) $data['items']) : [];
        list($generated, $missing) = $this->billableSources($consultation, $homecare);
        $rows = array_merge($generated, $manual);

        return $this->db->transaction(function () use ($patient, $consultation, $homecare, $data, $rows, $missing, $auth, $request): array {
            $uuid = Uuid::v4();
            $now = Clock::nowForDatabase();
            $id = $this->db->insert('invoices', [
                'uuid' => $uuid,
                'patient_id' => (int) $patient['id'],
                'consultation_id' => $consultation !== null ? (int) $consultation['id'] : null,
                'homecare_request_id' => $homecare !== null ? (int) $homecare['id'] : null,
                'status' => 'BROUILLON',
                'currency' => $this->currency(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($rows as $row) {
                $this->insertItem($id, $row, $auth);
            }
            $this->recompute($id);
            $this->audit->record('INVOICE_DRAFTED', $request, 'invoice', $uuid, null, ['items' => count($rows)]);
            return $this->present((array) $this->db->fetchOne('SELECT * FROM invoices WHERE id = ?', [$id]), true, true)
                + ['missing_tariffs' => $missing];
        });
    }

    public function update(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'notes' => 'nullable|string|max:500',
            'discount_amount' => 'integer|min:0|max:' . self::MAX_AMOUNT,
            'version' => 'nullable|integer|min:1',
        ]);
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $invoice = $this->lockDraft($uuid);
            if (isset($data['version']) && (int) $data['version'] !== (int) $invoice['version']) {
                throw HttpException::conflict('La facture a été modifiée entre-temps.', 'VERSION_CONFLICT');
            }
            if (isset($data['discount_amount']) && (int) $data['discount_amount'] > (int) $invoice['total_amount']) {
                throw new ValidationException(['discount_amount' => ['La remise ne peut pas dépasser le total.']]);
            }
            $changes = array_intersect_key($data, ['notes' => 1, 'discount_amount' => 1]);
            $this->db->update('invoices', array_intersect_key($changes, ['notes' => 1]) + ['updated_by' => $auth->userId(), 'updated_at' => Clock::nowForDatabase()], 'id = ?', [(int) $invoice['id']]);
            // La remise et les totaux changent ensemble (contrainte net = total - remise).
            $this->recompute((int) $invoice['id'], isset($data['discount_amount']) ? (int) $data['discount_amount'] : null);
            $this->audit->record('INVOICE_UPDATED', $request, 'invoice', $uuid, null, ['fields' => array_keys($changes)]);
            return $this->get($uuid);
        });
    }

    public function addItem(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $rows = $this->manualItems([$input]);
        return $this->db->transaction(function () use ($uuid, $rows, $auth, $request): array {
            $invoice = $this->lockDraft($uuid);
            $order = (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM invoice_items WHERE invoice_id = ?', [(int) $invoice['id']]);
            $this->insertItem((int) $invoice['id'], ['sort_order' => $order] + $rows[0], $auth);
            $this->recompute((int) $invoice['id']);
            $this->audit->record('INVOICE_ITEM_ADDED', $request, 'invoice', $uuid, null, ['type' => $rows[0]['item_type']]);
            return $this->get($uuid);
        });
    }

    public function removeItem(string $uuid, string $itemUuid, Request $request): array
    {
        $auth = self::auth($request);
        return $this->db->transaction(function () use ($uuid, $itemUuid, $auth, $request): array {
            $invoice = $this->lockDraft($uuid);
            $now = Clock::nowForDatabase();
            $removed = $this->db->execute(
                'UPDATE invoice_items SET deleted_at = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE uuid = ? AND invoice_id = ? AND deleted_at IS NULL',
                [$now, $auth->userId(), $now, $itemUuid, (int) $invoice['id']]
            );
            if ($removed === 0) {
                throw HttpException::notFound('Ligne introuvable.');
            }
            $this->recompute((int) $invoice['id']);
            $this->audit->record('INVOICE_ITEM_REMOVED', $request, 'invoice', $uuid, null, ['item' => $itemUuid]);
            return $this->get($uuid);
        });
    }

    // ------------------------------------------------------------------ Émission, annulation, règlement

    public function issue(string $uuid, Request $request): array
    {
        $auth = self::auth($request);
        $invoice = $this->db->transaction(function () use ($uuid, $auth, $request): array {
            $invoice = $this->lockDraft($uuid);
            if ((int) $this->db->fetchValue('SELECT COUNT(*) FROM invoice_items WHERE invoice_id = ? AND deleted_at IS NULL', [(int) $invoice['id']]) === 0) {
                throw new ValidationException(['items' => ['Une facture sans ligne ne peut pas être émise.']]);
            }
            $now = Clock::nowForDatabase();
            $year = Clock::now()->setTimezone($this->timezone())->format('Y');
            $prefix = strtoupper((string) $this->settings->get('invoices.number_prefix', 'FAC'));
            $number = sprintf('%s-%s-%06d', $prefix, $year, $this->sequences->next('invoice_number', $year));
            $this->db->update('invoices', [
                'status' => 'EMISE',
                'number' => $number,
                'issued_at' => $now,
                'issued_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'updated_at' => $now,
            ], 'id = ?', [(int) $invoice['id']]);
            $this->bump((int) $invoice['id']);
            $this->history((int) $invoice['id'], 'STATUS', 'BROUILLON', 'EMISE', $auth);
            if ($invoice['homecare_request_id'] !== null) {
                $this->setHomecareStatus((int) $invoice['homecare_request_id'], 'TERMINEE', 'FACTUREE', $auth, 'Facture ' . $number);
            }
            $this->journal->record('invoice', $uuid, ChangeJournal::UPSERT, (int) $invoice['patient_id']);
            $this->audit->record('INVOICE_ISSUED', $request, 'invoice', $uuid, null, ['number' => $number]);
            return $invoice;
        });
        $patient = $this->patients->findById((int) $invoice['patient_id']);
        if ($patient !== null && $patient['user_id'] !== null) {
            // Ni montant ni détail dans la notification (D-004).
            $this->notifications->notify((int) $patient['user_id'], 'INVOICE_ISSUED', 'Nouvelle facture', 'Une facture est disponible dans votre dossier.', 'invoice', $uuid);
        }
        return $this->get($uuid);
    }

    public function cancel(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, ['reason' => 'required|string|max:500']);
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $invoice = $this->lock($uuid);
            if ($invoice['status'] === 'ANNULEE') {
                throw HttpException::conflict('Cette facture est déjà annulée.', 'INVALID_TRANSITION');
            }
            if ($invoice['settlement_status'] !== 'NON_REGLEE') {
                throw HttpException::conflict('Un règlement est déclaré sur cette facture : remettez-la à « non réglée » avant de l\'annuler.', 'SETTLEMENT_DECLARED');
            }
            $now = Clock::nowForDatabase();
            $this->db->update('invoices', [
                'status' => 'ANNULEE',
                'cancelled_at' => $now,
                'cancelled_by' => $auth->userId(),
                'cancel_reason' => $data['reason'],
                'updated_by' => $auth->userId(),
                'updated_at' => $now,
            ], 'id = ?', [(int) $invoice['id']]);
            $this->bump((int) $invoice['id']);
            $this->history((int) $invoice['id'], 'STATUS', (string) $invoice['status'], 'ANNULEE', $auth, null, $data['reason']);
            if ($invoice['status'] === 'EMISE') {
                if ($invoice['homecare_request_id'] !== null) {
                    // La visite redevient facturable.
                    $this->setHomecareStatus((int) $invoice['homecare_request_id'], 'FACTUREE', 'TERMINEE', $auth, 'Annulation de la facture ' . $invoice['number']);
                }
                $this->journal->record('invoice', $uuid, ChangeJournal::UPSERT, (int) $invoice['patient_id']);
            }
            $this->audit->record('INVOICE_CANCELLED', $request, 'invoice', $uuid, ['status' => $invoice['status']], ['status' => 'ANNULEE']);
            return $this->get($uuid);
        });
    }

    /**
     * Déclaration de l'état de règlement (information seulement : aucun paiement n'est traité, D-004).
     */
    public function declareSettlement(string $uuid, array $input, Request $request): array
    {
        $auth = self::auth($request);
        $data = $this->validator->validate($input, [
            'settlement_status' => 'required|in:NON_REGLEE,PARTIELLEMENT_REGLEE,REGLEE',
            'declared_paid_amount' => 'nullable|integer|min:0|max:' . self::MAX_AMOUNT,
            'comment' => 'nullable|string|max:500',
        ]);
        return $this->db->transaction(function () use ($uuid, $data, $auth, $request): array {
            $invoice = $this->lock($uuid);
            if ($invoice['status'] !== 'EMISE') {
                throw HttpException::conflict('Le règlement ne se déclare que sur une facture émise.', 'INVALID_TRANSITION');
            }
            $net = (int) $invoice['net_amount'];
            $status = $data['settlement_status'];
            $amount = isset($data['declared_paid_amount']) ? (int) $data['declared_paid_amount'] : null;
            if ($status === 'NON_REGLEE') {
                $amount = $amount ?? 0;
                $valid = $amount === 0;
            } elseif ($status === 'REGLEE') {
                $amount = $amount ?? $net;
                $valid = $amount === $net;
            } else {
                $valid = $amount !== null && $amount > 0 && $amount < $net;
            }
            if (!$valid) {
                $formatted = DocumentBranding::money($net, (string) $invoice['currency']);
                throw new ValidationException(['declared_paid_amount' => [sprintf(
                    'Montant incohérent avec l\'état déclaré (non réglée : 0 ; partiellement réglée : entre 0 et %s exclus ; réglée : %s).',
                    $formatted,
                    $formatted
                )]]);
            }
            $now = Clock::nowForDatabase();
            $this->db->update('invoices', [
                'settlement_status' => $status,
                'declared_paid_amount' => $amount,
                'settlement_declared_at' => $now,
                'settlement_declared_by' => $auth->userId(),
                'updated_by' => $auth->userId(),
                'updated_at' => $now,
            ], 'id = ?', [(int) $invoice['id']]);
            $this->bump((int) $invoice['id']);
            $this->history((int) $invoice['id'], 'SETTLEMENT', (string) $invoice['settlement_status'], $status, $auth, $amount, $data['comment'] ?? null);
            $this->journal->record('invoice', $uuid, ChangeJournal::UPSERT, (int) $invoice['patient_id']);
            $this->audit->record(
                'INVOICE_SETTLEMENT_DECLARED',
                $request,
                'invoice',
                $uuid,
                ['settlement_status' => $invoice['settlement_status'], 'declared_paid_amount' => (int) $invoice['declared_paid_amount']],
                ['settlement_status' => $status, 'declared_paid_amount' => $amount]
            );
            return $this->get($uuid);
        });
    }

    // ------------------------------------------------------------------ Présentation

    /**
     * @param bool $withItems   Inclure les lignes
     * @param bool $withHistory Inclure l'historique interne (personnel seulement)
     */
    public function present(array $row, bool $withItems, bool $withHistory): array
    {
        $patient = (array) $this->patients->findById((int) $row['patient_id']);
        $consultationUuid = $row['consultation_id'] !== null
            ? $this->db->fetchValue('SELECT uuid FROM consultations WHERE id = ?', [(int) $row['consultation_id']])
            : null;
        $homecareUuid = $row['homecare_request_id'] !== null
            ? $this->db->fetchValue('SELECT uuid FROM homecare_requests WHERE id = ?', [(int) $row['homecare_request_id']])
            : null;
        $invoice = [
            'id' => (string) $row['uuid'],
            'number' => $row['number'],
            'patient_id' => (string) $patient['uuid'],
            'patient' => ['id' => (string) $patient['uuid'], 'file_number' => $patient['file_number'], 'name' => $patient['first_name'] . ' ' . $patient['last_name']],
            'consultation_id' => $consultationUuid !== null ? (string) $consultationUuid : null,
            'homecare_request_id' => $homecareUuid !== null ? (string) $homecareUuid : null,
            'status' => (string) $row['status'],
            'currency' => (string) $row['currency'],
            'total_amount' => (int) $row['total_amount'],
            'discount_amount' => (int) $row['discount_amount'],
            'net_amount' => (int) $row['net_amount'],
            'settlement_status' => (string) $row['settlement_status'],
            'declared_paid_amount' => (int) $row['declared_paid_amount'],
            'outstanding_amount' => $row['status'] === 'EMISE' ? (int) $row['net_amount'] - (int) $row['declared_paid_amount'] : 0,
            'settlement_declared_at' => Clock::toIso($row['settlement_declared_at']),
            'issued_at' => Clock::toIso($row['issued_at']),
            'cancelled_at' => Clock::toIso($row['cancelled_at']),
            'cancel_reason' => $row['cancel_reason'],
            'notes' => $row['notes'],
            'version' => (int) $row['version'],
            'created_at' => Clock::toIso((string) $row['created_at']),
            'updated_at' => Clock::toIso((string) $row['updated_at']),
        ];
        if ($withItems) {
            $invoice['items'] = array_map(function (array $item): array {
                return [
                    'id' => (string) $item['uuid'],
                    'item_type' => (string) $item['item_type'],
                    'source_id' => $item['source_uuid'],
                    'description' => (string) $item['description'],
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => (int) $item['unit_price'],
                    'total_price' => (int) $item['total_price'],
                ];
            }, $this->db->fetchAll('SELECT * FROM invoice_items WHERE invoice_id = ? AND deleted_at IS NULL ORDER BY sort_order, id', [(int) $row['id']]));
        }
        if ($withHistory) {
            $invoice['history'] = array_map(function (array $entry): array {
                return [
                    'field' => (string) $entry['field_name'],
                    'from' => $entry['from_value'],
                    'to' => (string) $entry['to_value'],
                    'amount' => $entry['amount'] !== null ? (int) $entry['amount'] : null,
                    'by' => ['id' => (string) $entry['user_uuid'], 'name' => $entry['first_name'] . ' ' . $entry['last_name']],
                    'at' => Clock::toIso((string) $entry['changed_at']),
                    'comment' => $entry['comment'],
                ];
            }, $this->db->fetchAll(
                'SELECT h.*, u.uuid AS user_uuid, u.first_name, u.last_name FROM invoice_status_history h JOIN users u ON u.id = h.changed_by
                 WHERE h.invoice_id = ? ORDER BY h.id',
                [(int) $row['id']]
            ));
        }
        return $invoice;
    }

    public function findOrFail(string $uuid): array
    {
        $invoice = $this->db->fetchOne('SELECT * FROM invoices WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
        if ($invoice === null) {
            throw HttpException::notFound('Facture introuvable.');
        }
        return $invoice;
    }

    // ------------------------------------------------------------------ Interne

    /**
     * Soins réalisés et examens effectués non encore facturés, au tarif en vigueur à leur date.
     *
     * @return array{0: array[], 1: array[]} Lignes, et éléments sans tarif applicable
     */
    private function billableSources(?array $consultation, ?array $homecare): array
    {
        if ($consultation === null && $homecare === null) {
            return [[], []];
        }
        $consultationIds = [];
        if ($consultation !== null) {
            $consultationIds[] = (int) $consultation['id'];
        }
        if ($homecare !== null) {
            foreach ($this->db->fetchAll("SELECT id FROM consultations WHERE homecare_request_id = ? AND status <> 'ANNULEE' AND deleted_at IS NULL", [(int) $homecare['id']]) as $row) {
                $consultationIds[] = (int) $row['id'];
            }
        }
        $consultationIds = array_values(array_unique($consultationIds));
        $in = $consultationIds === [] ? 'NULL' : implode(', ', array_fill(0, count($consultationIds), '?'));
        $homecareId = $homecare !== null ? (int) $homecare['id'] : 0;
        $alreadyBilled = "SELECT ii.source_uuid FROM invoice_items ii JOIN invoices iv ON iv.id = ii.invoice_id
                          WHERE ii.source_uuid IS NOT NULL AND ii.deleted_at IS NULL AND iv.status <> 'ANNULEE' AND iv.deleted_at IS NULL";

        $sources = [];
        foreach ($this->db->fetchAll(
            "SELECT t.uuid, t.treatment_type_id AS item_id, t.performed_at AS at, tt.label
             FROM treatments t JOIN treatment_types tt ON tt.id = t.treatment_type_id
             WHERE t.status = 'REALISE' AND t.deleted_at IS NULL AND (t.consultation_id IN (" . $in . ') OR t.homecare_request_id = ?)
               AND t.uuid NOT IN (' . $alreadyBilled . ') ORDER BY t.performed_at, t.id',
            array_merge($consultationIds, [$homecareId])
        ) as $row) {
            $sources[] = ['TREATMENT', 'TREATMENT_TYPE', $row];
        }
        foreach ($this->db->fetchAll(
            "SELECT e.uuid, e.examination_type_id AS item_id, COALESCE(e.completed_at, e.performed_at, e.prescribed_at) AS at, et.label
             FROM examinations e JOIN examination_types et ON et.id = e.examination_type_id
             WHERE e.status IN ('TERMINE', 'VALIDE') AND e.deleted_at IS NULL AND e.consultation_id IN (" . $in . ')
               AND e.uuid NOT IN (' . $alreadyBilled . ') ORDER BY e.prescribed_at, e.id',
            $consultationIds
        ) as $row) {
            $sources[] = ['EXAMINATION', 'EXAMINATION_TYPE', $row];
        }

        $rows = [];
        $missing = [];
        foreach ($sources as $index => $source) {
            list($itemType, $tariffType, $row) = $source;
            $date = $this->localDate((string) $row['at']);
            $tariff = $this->tariffs->priceAt($tariffType, (int) $row['item_id'], $date);
            if ($tariff === null) {
                $missing[] = ['item_type' => $itemType, 'source_id' => (string) $row['uuid'], 'description' => (string) $row['label'], 'date' => $date];
                continue;
            }
            $rows[] = [
                'item_type' => $itemType,
                'source_uuid' => (string) $row['uuid'],
                'tariff_id' => (int) $tariff['id'],
                'description' => (string) $row['label'],
                'quantity' => 1,
                'unit_price' => (int) $tariff['amount'],
                'sort_order' => $index,
            ];
        }
        return [$rows, $missing];
    }

    /**
     * Lignes saisies : acte ou médicament du référentiel (tarif du jour), ou ligne libre « OTHER ».
     */
    private function manualItems(array $items): array
    {
        $rows = [];
        $errors = [];
        foreach (array_values($items) as $index => $item) {
            $prefix = 'items.' . $index . '.';
            try {
                $data = $this->validator->validate(is_array($item) ? $item : [], [
                    'item_type' => 'required|in:MEDICAL_ACT,MEDICATION,OTHER',
                    'reference_id' => 'nullable|uuid',
                    'description' => 'nullable|string|max:255',
                    'quantity' => 'nullable|integer|min:1|max:1000',
                    'unit_price' => 'nullable|integer|min:0|max:' . self::MAX_AMOUNT,
                ]);
            } catch (ValidationException $exception) {
                foreach ($exception->getErrors() as $field => $messages) {
                    $errors[$prefix . $field] = $messages;
                }
                continue;
            }
            $row = [
                'item_type' => $data['item_type'],
                'quantity' => (int) ($data['quantity'] ?? 1),
                'source_uuid' => null,
                'tariff_id' => null,
                'sort_order' => 1000 + $index,
            ];
            if ($data['item_type'] === 'OTHER') {
                if (!isset($data['description'], $data['unit_price'])) {
                    $errors[$prefix . 'unit_price'] = ['Une ligne libre exige un libellé et un prix.'];
                    continue;
                }
                $rows[] = $row + ['description' => $data['description'], 'unit_price' => (int) $data['unit_price']];
                continue;
            }
            if (isset($data['unit_price'])) {
                $errors[$prefix . 'unit_price'] = ['Le prix vient du tarif en vigueur : il ne se saisit pas.'];
                continue;
            }
            list($table, $tariffType, $labelColumn) = self::MANUAL_REFERENCES[$data['item_type']];
            $reference = isset($data['reference_id'])
                ? $this->db->fetchOne('SELECT * FROM `' . $table . '` WHERE uuid = ? AND deleted_at IS NULL AND active = 1', [$data['reference_id']])
                : null;
            if ($reference === null) {
                $errors[$prefix . 'reference_id'] = ['Élément du référentiel introuvable ou désactivé.'];
                continue;
            }
            $tariff = $this->tariffs->priceAt($tariffType, (int) $reference['id'], $this->tariffs->today());
            if ($tariff === null) {
                $errors[$prefix . 'reference_id'] = ['Aucun tarif en vigueur pour cet élément.'];
                continue;
            }
            $rows[] = $row + [
                'tariff_id' => (int) $tariff['id'],
                'description' => $data['description'] ?? (string) $reference[$labelColumn],
                'unit_price' => (int) $tariff['amount'],
            ];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $rows;
    }

    private function insertItem(int $invoiceId, array $row, AuthContext $auth): void
    {
        $now = Clock::nowForDatabase();
        $this->db->insert('invoice_items', [
            'uuid' => Uuid::v4(),
            'invoice_id' => $invoiceId,
            'item_type' => $row['item_type'],
            'source_uuid' => $row['source_uuid'],
            'tariff_id' => $row['tariff_id'],
            'description' => $row['description'],
            'quantity' => (int) $row['quantity'],
            'unit_price' => (int) $row['unit_price'],
            'total_price' => (int) $row['quantity'] * (int) $row['unit_price'],
            'sort_order' => (int) $row['sort_order'],
            'created_by' => $auth->userId(),
            'updated_by' => $auth->userId(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Recalcule les totaux. Une remise supérieure au nouveau total est ramenée au total.
     */
    private function recompute(int $invoiceId, ?int $discount = null): void
    {
        $total = (int) $this->db->fetchValue('SELECT COALESCE(SUM(total_price), 0) FROM invoice_items WHERE invoice_id = ? AND deleted_at IS NULL', [$invoiceId]);
        $discount = min($discount ?? (int) $this->db->fetchValue('SELECT discount_amount FROM invoices WHERE id = ?', [$invoiceId]), $total);
        $this->db->execute(
            'UPDATE invoices SET total_amount = ?, discount_amount = ?, net_amount = ?, version = version + 1 WHERE id = ?',
            [$total, $discount, $total - $discount, $invoiceId]
        );
    }

    private function setHomecareStatus(int $requestId, string $from, string $to, AuthContext $auth, string $comment): void
    {
        $request = $this->db->fetchOne('SELECT uuid, patient_id, assigned_team_id, status FROM homecare_requests WHERE id = ? FOR UPDATE', [$requestId]);
        if ($request === null || $request['status'] !== $from) {
            return;
        }
        $now = Clock::nowForDatabase();
        $this->db->execute('UPDATE homecare_requests SET status = ?, updated_by = ?, updated_at = ?, version = version + 1 WHERE id = ?', [$to, $auth->userId(), $now, $requestId]);
        $this->db->insert('homecare_status_history', [
            'uuid' => Uuid::v4(),
            'request_id' => $requestId,
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $auth->userId(),
            'changed_at' => $now,
            'received_at' => $now,
            'comment' => $comment,
            'device_id' => $auth->deviceId(),
        ]);
        $this->journal->record(
            'homecare_request',
            (string) $request['uuid'],
            ChangeJournal::UPSERT,
            (int) $request['patient_id'],
            $request['assigned_team_id'] !== null ? (int) $request['assigned_team_id'] : null
        );
    }

    private function history(int $invoiceId, string $field, ?string $from, string $to, AuthContext $auth, ?int $amount = null, ?string $comment = null): void
    {
        $this->db->insert('invoice_status_history', [
            'invoice_id' => $invoiceId,
            'field_name' => $field,
            'from_value' => $from,
            'to_value' => $to,
            'amount' => $amount,
            'changed_by' => $auth->userId(),
            'changed_at' => Clock::nowForDatabase(),
            'comment' => $comment,
        ]);
    }

    /**
     * @return array{0: string[], 1: array}
     */
    private function filters(array $data): array
    {
        $where = ['i.deleted_at IS NULL'];
        $params = [];
        if (isset($data['patient_id'])) {
            $where[] = 'p.uuid = ?';
            $params[] = $data['patient_id'];
        }
        if (isset($data['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $data['status'];
        }
        if (isset($data['settlement_status'])) {
            $where[] = "i.settlement_status = ? AND i.status = 'EMISE'";
            $params[] = $data['settlement_status'];
        }
        if (isset($data['number'])) {
            $where[] = 'i.number = ?';
            $params[] = strtoupper($data['number']);
        }
        if (isset($data['from'])) {
            $where[] = 'COALESCE(i.issued_at, i.created_at) >= ?';
            $params[] = $data['from'] . ' 00:00:00';
        }
        if (isset($data['to'])) {
            $where[] = 'COALESCE(i.issued_at, i.created_at) <= ?';
            $params[] = $data['to'] . ' 23:59:59';
        }
        return [$where, $params];
    }

    private function lockDraft(string $uuid): array
    {
        $invoice = $this->lock($uuid);
        if ($invoice['status'] !== 'BROUILLON') {
            throw HttpException::conflict('Une facture émise ou annulée n\'est plus modifiable.', 'INVALID_TRANSITION');
        }
        return $invoice;
    }

    private function lock(string $uuid): array
    {
        $invoice = $this->db->fetchOne('SELECT * FROM invoices WHERE uuid = ? AND deleted_at IS NULL FOR UPDATE', [$uuid]);
        if ($invoice === null) {
            throw HttpException::notFound('Facture introuvable.');
        }
        return $invoice;
    }

    private function bump(int $invoiceId): void
    {
        $this->db->execute('UPDATE invoices SET version = version + 1 WHERE id = ?', [$invoiceId]);
    }

    private function localDate(string $utcDateTime): string
    {
        return (new \DateTimeImmutable($utcDateTime, new \DateTimeZone('UTC')))->setTimezone($this->timezone())->format('Y-m-d');
    }

    private function timezone(): \DateTimeZone
    {
        return new \DateTimeZone((string) $this->settings->get('app.timezone', 'Africa/Niamey'));
    }

    private function currency(): string
    {
        return strtoupper((string) $this->settings->get('app.currency', 'XOF'));
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
