<?php

declare(strict_types=1);

namespace Vsh\Modules\Patients;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

final class PatientRepository
{
    /** Tables dont la colonne patient_id n'est pas réaffectée lors d'une fusion. */
    private const MERGE_EXCLUDED_TABLES = ['sync_changes', 'patient_medical_profiles', 'sync_patient_subscriptions'];

    /** Recherche par nom : total compté jusqu'à cette limite (« plus de 1 000 résultats, précisez »). */
    public const SEARCH_TOTAL_CAP = 1000;

    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM patients WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public function findByUuid(string $uuid, bool $forUpdate = false): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM patients WHERE uuid = ? AND deleted_at IS NULL' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$uuid]
        );
    }

    public function findByFileNumber(string $fileNumber): ?array
    {
        return $this->db->fetchOne('SELECT * FROM patients WHERE file_number = ? AND deleted_at IS NULL', [$fileNumber]);
    }

    public function uuidExists(string $uuid): bool
    {
        return $this->db->fetchValue('SELECT id FROM patients WHERE uuid = ?', [$uuid]) !== null;
    }

    public function create(array $row): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert('patients', $row + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function update(int $id, array $row): void
    {
        $this->db->update('patients', $row + ['updated_at' => Clock::nowForDatabase()], 'id = ?', [$id]);
        $this->db->execute('UPDATE patients SET version = version + 1 WHERE id = ?', [$id]);
    }

    /**
     * @param array{q?: string, file_number?: string, phone?: string, birth_date?: string, status?: string} $criteria
     * @return array{0: array[], 1: int}
     */
    public function search(array $criteria, int $limit, int $offset): array
    {
        $where = ['deleted_at IS NULL', "status <> 'MERGED'"];
        $params = [];
        foreach (['file_number', 'phone', 'birth_date', 'status'] as $column) {
            if (isset($criteria[$column])) {
                $where[] = $column . ' = ?';
                $params[] = $criteria[$column];
            }
        }
        $words = isset($criteria['q']) ? array_values(array_filter(preg_split('/\s+/', trim($criteria['q'])) ?: [], 'strlen')) : [];
        if ($words === []) {
            // Critères exacts (dossier, téléphone, date de naissance) : index dédiés, comptage exact bon marché.
            $sql = implode(' AND ', $where);
            $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM patients WHERE ' . $sql, $params);
            $rows = $this->db->fetchAll(
                'SELECT * FROM patients WHERE ' . $sql . ' ORDER BY last_name, first_name, id LIMIT ? OFFSET ?',
                array_merge($params, [$limit, $offset])
            );
            return [$rows, $total];
        }

        // Nom : chaque mot commence le prénom ou le nom (ordre indifférent). Le premier mot est cherché
        // par deux branches indexées réunies (UNION) : un OR entre prénom et nom empêche MySQL d'utiliser
        // les index (mesuré sur 1 M de dossiers : 2,4 s par page, contre 3 ms). Les mots suivants filtrent
        // ensuite ce petit ensemble. Le total est plafonné (SEARCH_TOTAL_CAP) : au-delà, préciser la recherche.
        $likes = array_map(function (string $word): string {
            return addcslashes($word, '%_\\') . '%';
        }, $words);
        $common = $where;
        $commonParams = $params;
        foreach (array_slice($likes, 1) as $like) {
            $common[] = '(first_name LIKE ? OR last_name LIKE ?)';
            array_push($commonParams, $like, $like);
        }
        $filter = implode(' AND ', $common);
        $branches = function (string $select, string $order, int $cap) use ($filter): string {
            return '(SELECT ' . $select . ' FROM patients WHERE last_name LIKE ? AND ' . $filter . $order . ' LIMIT ' . $cap . ')'
                . ' UNION (SELECT ' . $select . ' FROM patients WHERE first_name LIKE ? AND ' . $filter . $order . ' LIMIT ' . $cap . ')';
        };
        $branchParams = array_merge([$likes[0]], $commonParams, [$likes[0]], $commonParams);

        $window = $offset + $limit;
        $rows = $this->db->fetchAll(
            'SELECT * FROM (' . $branches('*', ' ORDER BY last_name, first_name, id', $window) . ') p ORDER BY last_name, first_name, id LIMIT ? OFFSET ?',
            array_merge($branchParams, [$limit, $offset])
        );
        $total = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM (' . $branches('id', '', self::SEARCH_TOTAL_CAP) . ') c',
            $branchParams
        );
        return [$rows, min($total, self::SEARCH_TOTAL_CAP)];
    }

    /**
     * @return array{0: array[], 1: int}
     */
    public function paginateByStatus(string $status, int $limit, int $offset): array
    {
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM patients WHERE status = ? AND deleted_at IS NULL', [$status]);
        $rows = $this->db->fetchAll(
            'SELECT * FROM patients WHERE status = ? AND deleted_at IS NULL ORDER BY created_at, id LIMIT ? OFFSET ?',
            [$status, $limit, $offset]
        );
        return [$rows, $total];
    }

    /**
     * Dossiers susceptibles de désigner la même personne : mêmes nom, prénom et date de naissance,
     * ou même téléphone et même prénom. La collation de la base ignore la casse et les accents.
     */
    public function duplicateCandidates(string $firstName, string $lastName, ?string $birthDate, ?string $phone, ?int $excludeId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM patients
             WHERE deleted_at IS NULL AND status NOT IN ('MERGED', 'REJECTED') AND id <> ?
               AND (
                    (birth_date IS NOT NULL AND birth_date = ? AND last_name = ? AND first_name = ?)
                 OR (phone IS NOT NULL AND phone = ? AND first_name = ?)
               )
             ORDER BY status = 'ACTIVE' DESC, id
             LIMIT 5",
            [$excludeId ?? 0, $birthDate, $lastName, $firstName, $phone, $firstName]
        );
    }

    public function forUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM patients WHERE user_id = ? AND deleted_at IS NULL AND status <> 'MERGED' ORDER BY created_at, id",
            [$userId]
        );
    }

    public function countOpenForUser(int $userId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM patients WHERE user_id = ? AND deleted_at IS NULL AND status IN ('ACTIVE', 'PENDING')",
            [$userId]
        );
    }

    public function medicalProfileByUuid(string $uuid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM patient_medical_profiles WHERE uuid = ?', [$uuid]);
    }

    public function medicalProfile(int $patientId): ?array
    {
        return $this->db->fetchOne('SELECT * FROM patient_medical_profiles WHERE patient_id = ?', [$patientId]);
    }

    /**
     * @return array<int,array{uuid: string, name: string}>
     */
    public function usersSummary(array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));
        if ($userIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, uuid, first_name, last_name FROM users WHERE id IN (' . implode(', ', array_fill(0, count($userIds), '?')) . ')',
            $userIds
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = ['uuid' => (string) $row['uuid'], 'name' => $row['first_name'] . ' ' . $row['last_name']];
        }
        return $map;
    }

    /**
     * @return array<int,string> id => uuid
     */
    public function patientUuids(array $patientIds): array
    {
        $patientIds = array_values(array_unique(array_filter($patientIds)));
        if ($patientIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, uuid FROM patients WHERE id IN (' . implode(', ', array_fill(0, count($patientIds), '?')) . ')',
            $patientIds
        );
        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['id']] = (string) $row['uuid'];
        }
        return $map;
    }

    /**
     * Rattache au dossier cible toutes les données du dossier fusionné, dans toutes les tables
     * possédant une colonne patient_id (y compris celles des modules ajoutés plus tard).
     *
     * @return string[] Tables modifiées
     */
    public function reassignAllData(int $fromPatientId, int $toPatientId): array
    {
        $tables = $this->db->fetchAll(
            "SELECT TABLE_NAME AS name FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'patient_id'"
        );
        $changed = [];
        foreach ($tables as $table) {
            $name = (string) $table['name'];
            if (in_array($name, self::MERGE_EXCLUDED_TABLES, true) || preg_match('/^[a-z_]+$/', $name) !== 1) {
                continue;
            }
            if ($this->db->execute('UPDATE `' . $name . '` SET patient_id = ? WHERE patient_id = ?', [$toPatientId, $fromPatientId]) > 0) {
                $changed[] = $name;
            }
        }
        // Dossiers épinglés : un utilisateur peut avoir épinglé les deux dossiers (clé unique).
        $this->db->execute(
            'INSERT IGNORE INTO sync_patient_subscriptions (user_id, patient_id, created_at)
             SELECT user_id, ?, created_at FROM sync_patient_subscriptions WHERE patient_id = ?',
            [$toPatientId, $fromPatientId]
        );
        $this->db->execute('DELETE FROM sync_patient_subscriptions WHERE patient_id = ?', [$fromPatientId]);
        if ($this->medicalProfile($toPatientId) === null
            && $this->db->execute('UPDATE patient_medical_profiles SET patient_id = ? WHERE patient_id = ?', [$toPatientId, $fromPatientId]) > 0) {
            $changed[] = 'patient_medical_profiles';
        }
        return $changed;
    }
}
