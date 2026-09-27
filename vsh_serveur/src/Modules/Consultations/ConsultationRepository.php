<?php

declare(strict_types=1);

namespace Vsh\Modules\Consultations;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

final class ConsultationRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findByUuid(string $uuid, bool $forUpdate = false): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM consultations WHERE uuid = ? AND deleted_at IS NULL' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$uuid]
        );
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM consultations WHERE id = ?', [$id]);
    }

    public function uuidExists(string $table, string $uuid): bool
    {
        return $this->db->fetchValue('SELECT id FROM `' . $table . '` WHERE uuid = ?', [$uuid]) !== null;
    }

    public function create(array $row): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert('consultations', $row + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function update(int $id, array $row): void
    {
        $this->db->update('consultations', $row + ['updated_at' => Clock::nowForDatabase()], 'id = ?', [$id]);
        $this->db->execute('UPDATE consultations SET version = version + 1 WHERE id = ?', [$id]);
    }

    /**
     * @param array{patient_id?: int, status?: string, consultation_type?: string, practitioner_id?: int, from?: string, to?: string} $filters
     * @return array{0: array[], 1: int}
     */
    public function paginate(array $filters, int $limit, int $offset): array
    {
        $where = ['deleted_at IS NULL'];
        $params = [];
        foreach (['patient_id', 'status', 'consultation_type', 'practitioner_id'] as $column) {
            if (isset($filters[$column])) {
                $where[] = $column . ' = ?';
                $params[] = $filters[$column];
            }
        }
        if (isset($filters['from'])) {
            $where[] = 'started_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $where[] = 'started_at < ?';
            $params[] = $filters['to'];
        }
        $sql = implode(' AND ', $where);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM consultations WHERE ' . $sql, $params);
        $rows = $this->db->fetchAll(
            'SELECT * FROM consultations WHERE ' . $sql . ' ORDER BY started_at DESC, id DESC LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        );
        return [$rows, $total];
    }

    public function insert(string $table, array $row): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert($table, $row + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function findRecord(string $table, string $uuid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `' . $table . '` WHERE uuid = ? AND deleted_at IS NULL', [$uuid]);
    }

    public function softDelete(string $table, int $id, ?int $actorId): void
    {
        $now = Clock::nowForDatabase();
        $this->db->update($table, ['deleted_at' => $now, 'updated_at' => $now, 'updated_by' => $actorId], 'id = ?', [$id]);
        $this->db->execute('UPDATE `' . $table . '` SET version = version + 1 WHERE id = ?', [$id]);
    }

    public function vitalsForConsultation(int $consultationId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM vital_signs WHERE consultation_id = ? AND deleted_at IS NULL ORDER BY recorded_at, id',
            [$consultationId]
        );
    }

    public function vitalsForPatient(int $patientId, int $limit): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM vital_signs WHERE patient_id = ? AND deleted_at IS NULL ORDER BY recorded_at DESC, id DESC LIMIT ?',
            [$patientId, $limit]
        );
    }

    public function diagnoses(int $consultationId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM consultation_diagnoses WHERE consultation_id = ? AND deleted_at IS NULL
             ORDER BY diagnosis_kind = 'PRINCIPAL' DESC, id",
            [$consultationId]
        );
    }

    public function notes(int $consultationId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM consultation_notes WHERE consultation_id = ? AND deleted_at IS NULL ORDER BY created_at, id',
            [$consultationId]
        );
    }

    /**
     * Soins rattachés à la consultation (lecture seule ; ils sont gérés par le module Treatments).
     */
    public function treatments(int $consultationId): array
    {
        return $this->db->fetchAll(
            'SELECT t.uuid, t.status, t.scheduled_for, t.performed_at, t.observations, tt.code AS type_code, tt.label AS type_label
             FROM treatments t JOIN treatment_types tt ON tt.id = t.treatment_type_id
             WHERE t.consultation_id = ? AND t.deleted_at IS NULL ORDER BY COALESCE(t.performed_at, t.scheduled_for), t.id',
            [$consultationId]
        );
    }

    /**
     * Utilisateur actif du personnel (praticien désigné).
     */
    public function activeStaffId(string $userUuid): ?int
    {
        $id = $this->db->fetchValue(
            "SELECT id FROM users WHERE uuid = ? AND account_type = 'STAFF' AND status = 'ACTIVE' AND deleted_at IS NULL",
            [$userUuid]
        );
        return $id === null ? null : (int) $id;
    }

    /**
     * @return array<int,array{id: string, name: string}>
     */
    public function people(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->fetchAll(
            'SELECT id, uuid, first_name, last_name FROM users WHERE id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')',
            $userIds
        ) as $row) {
            $map[(int) $row['id']] = ['id' => (string) $row['uuid'], 'name' => $row['first_name'] . ' ' . $row['last_name']];
        }
        return $map;
    }

    /**
     * @return array<int,string> id => uuid
     */
    /**
     * Identité minimale des patients (listes de travail) : uuid, n° de dossier, nom.
     *
     * @return array<int,array{id: string, file_number: ?string, name: string}>
     */
    public function patientSummaries(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->fetchAll(
            'SELECT id, uuid, file_number, first_name, last_name FROM patients WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) as $row) {
            $map[(int) $row['id']] = [
                'id' => (string) $row['uuid'],
                'file_number' => $row['file_number'],
                'name' => mb_strtoupper((string) $row['last_name']) . ' ' . $row['first_name'],
            ];
        }
        return $map;
    }

    public function uuids(string $table, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $map = [];
        foreach ($this->db->fetchAll(
            'SELECT id, uuid FROM `' . $table . '` WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
            $ids
        ) as $row) {
            $map[(int) $row['id']] = (string) $row['uuid'];
        }
        return $map;
    }
}
