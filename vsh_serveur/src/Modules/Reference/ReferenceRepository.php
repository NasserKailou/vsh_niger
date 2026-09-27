<?php

declare(strict_types=1);

namespace Vsh\Modules\Reference;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

/**
 * Accès générique aux tables de référentiel. Les noms de tables et de colonnes proviennent
 * exclusivement de ReferenceCatalog (jamais de la requête HTTP).
 */
final class ReferenceRepository
{
    /** @var Database */
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * @param array{0: string, 1: int}|null $parent Colonne de rattachement et identifiant du parent
     * @return array{0: array[], 1: int}
     */
    public function paginate(
        string $table,
        array $searchColumns,
        ?string $search,
        ?bool $active,
        string $order,
        int $limit,
        int $offset
    ): array {
        list($where, $params) = $this->filters($searchColumns, $search, $active);
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where, $params);
        $rows = $this->db->fetchAll(
            'SELECT * FROM `' . $table . '` WHERE ' . $where . ' ORDER BY ' . $order . ', id LIMIT ? OFFSET ?',
            array_merge($params, [$limit, $offset])
        );
        return [$rows, $total];
    }

    public function children(string $table, string $foreignKey, int $parentId, string $order): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM `' . $table . '` WHERE `' . $foreignKey . '` = ? ORDER BY ' . $order . ', id',
            [$parentId]
        );
    }

    /**
     * @param int[] $parentIds
     * @return array<int,array[]>
     */
    public function childrenOf(string $table, string $foreignKey, array $parentIds, string $order): array
    {
        if ($parentIds === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM `' . $table . '` WHERE `' . $foreignKey . '` IN (' . implode(', ', array_fill(0, count($parentIds), '?')) . ')
             ORDER BY ' . $order . ', id',
            array_values($parentIds)
        );
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(int) $row[$foreignKey]][] = $row;
        }
        return $grouped;
    }

    public function findByUuid(string $table, string $uuid): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `' . $table . '` WHERE uuid = ?', [$uuid]);
    }

    public function findById(string $table, int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM `' . $table . '` WHERE id = ?', [$id]);
    }

    /**
     * @param mixed $value
     */
    public function valueTaken(string $table, string $column, $value, ?int $exceptId, ?array $scope = null): bool
    {
        $sql = 'SELECT id FROM `' . $table . '` WHERE `' . $column . '` = ? AND id <> ?';
        $params = [$value, $exceptId ?? 0];
        if ($scope !== null) {
            $sql .= ' AND `' . $scope[0] . '` = ?';
            $params[] = $scope[1];
        }
        return $this->db->fetchValue($sql, $params) !== null;
    }

    public function insert(string $table, array $row): int
    {
        $now = Clock::nowForDatabase();
        return $this->db->insert($table, $row + ['created_at' => $now, 'updated_at' => $now]);
    }

    public function update(string $table, int $id, array $row): void
    {
        $this->db->update($table, $row + ['updated_at' => Clock::nowForDatabase()], 'id = ?', [$id]);
        $this->db->execute('UPDATE `' . $table . '` SET version = version + 1 WHERE id = ?', [$id]);
    }

    /**
     * Marque le parent comme modifié quand une sous-ressource change (synchronisation incrémentale).
     */
    public function touch(string $table, int $id): void
    {
        $this->update($table, $id, []);
    }

    /**
     * Éléments modifiés depuis une date (inclusif), ou tous si $since est null.
     */
    public function changedSince(string $table, ?string $since, string $order): array
    {
        if ($since === null) {
            return $this->db->fetchAll('SELECT * FROM `' . $table . '` ORDER BY ' . $order . ', id');
        }
        return $this->db->fetchAll('SELECT * FROM `' . $table . '` WHERE updated_at >= ? ORDER BY ' . $order . ', id', [$since]);
    }

    /**
     * @return array{0: string, 1: array}
     */
    private function filters(array $searchColumns, ?string $search, ?bool $active): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($active !== null) {
            $where[] = 'active = ?';
            $params[] = $active;
        }
        if ($search !== null && $searchColumns !== []) {
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $clauses = [];
            foreach ($searchColumns as $column) {
                $clauses[] = '`' . $column . '` LIKE ?';
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $clauses) . ')';
        }
        return [implode(' AND ', $where), $params];
    }
}
