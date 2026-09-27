<?php

declare(strict_types=1);

namespace Vsh\Core;

/**
 * Accès MariaDB/MySQL via PDO : requêtes préparées natives uniquement, mode SQL strict, dates en UTC.
 *
 * Note : les requêtes préparées natives n'autorisent pas deux fois le même paramètre nommé dans une requête.
 */
final class Database
{
    private const SQL_MODE = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
    private const IDENTIFIER = '/^[a-z_][a-z0-9_]*$/';

    /** @var array<string,mixed> */
    private $config;

    /** @var \PDO|null */
    private $pdo;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function pdo(): \PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                (string) $this->config['host'],
                (int) $this->config['port'],
                (string) $this->config['database']
            );
            $pdo = new \PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);
            $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "', time_zone = '+00:00'");
            $this->pdo = $pdo;
        }
        return $this->pdo;
    }

    public function databaseName(): string
    {
        return (string) $this->config['database'];
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @return mixed
     */
    public function fetchValue(string $sql, array $params = [])
    {
        $value = $this->run($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    /**
     * @return int Nombre de lignes affectées
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    /**
     * @return int Identifiant auto-incrémenté
     */
    public function insert(string $table, array $row): int
    {
        if ($row === []) {
            throw new \InvalidArgumentException('Aucune colonne à insérer.');
        }
        $columns = array_keys($row);
        $this->assertIdentifiers(array_merge([$table], $columns));
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(function (string $column): string {
                return '`' . $column . '`';
            }, $columns)),
            implode(', ', array_fill(0, count($columns), '?'))
        );
        $this->run($sql, array_values($row));
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param string $where Condition avec des paramètres positionnels (?) uniquement
     */
    public function update(string $table, array $row, string $where, array $whereParams = []): int
    {
        if ($row === []) {
            throw new \InvalidArgumentException('Aucune colonne à modifier.');
        }
        $columns = array_keys($row);
        $this->assertIdentifiers(array_merge([$table], $columns));
        $assignments = implode(', ', array_map(function (string $column): string {
            return '`' . $column . '` = ?';
        }, $columns));
        return $this->execute(
            sprintf('UPDATE `%s` SET %s WHERE %s', $table, $assignments, $where),
            array_merge(array_values($row), array_values($whereParams))
        );
    }

    /**
     * Exécute le traitement dans une transaction ; les appels imbriqués rejoignent la transaction en cours.
     *
     * @return mixed
     */
    public function transaction(callable $callback)
    {
        $pdo = $this->pdo();
        if ($pdo->inTransaction()) {
            return $callback($this);
        }
        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function ping(): bool
    {
        try {
            return (int) $this->fetchValue('SELECT 1') === 1;
        } catch (\Throwable $exception) {
            $this->pdo = null;
            return false;
        }
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $placeholder = is_int($key) ? $key + 1 : (strpos($key, ':') === 0 ? $key : ':' . $key);
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            if ($value === null) {
                $type = \PDO::PARAM_NULL;
            } elseif (is_int($value)) {
                $type = \PDO::PARAM_INT;
            } else {
                $type = \PDO::PARAM_STR;
                $value = (string) $value;
            }
            $statement->bindValue($placeholder, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    /**
     * @param string[] $identifiers
     */
    private function assertIdentifiers(array $identifiers): void
    {
        foreach ($identifiers as $identifier) {
            if (!is_string($identifier) || preg_match(self::IDENTIFIER, $identifier) !== 1) {
                throw new \InvalidArgumentException('Nom de table ou de colonne invalide.');
            }
        }
    }
}
