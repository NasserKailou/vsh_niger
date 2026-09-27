<?php

declare(strict_types=1);

namespace Vsh\Core\Migrations;

use Vsh\Core\Database;
use Vsh\Core\Support\Clock;

/**
 * Applique les fichiers SQL de database/migrations dans l'ordre, une seule fois chacun
 * (suivi dans schema_migrations), et rejoue les seeds idempotents de database/seeds.
 *
 * MySQL/MariaDB valident implicitement les instructions DDL : une migration interrompue ne peut pas
 * être annulée automatiquement. Elle n'est enregistrée qu'après succès complet ; en cas d'échec,
 * le message indique le fichier et l'instruction à corriger.
 */
final class Migrator
{
    /** @var Database */
    private $db;

    /** @var string */
    private $migrationsPath;

    /** @var string */
    private $seedsPath;

    public function __construct(Database $db, string $migrationsPath, string $seedsPath)
    {
        $this->db = $db;
        $this->migrationsPath = $migrationsPath;
        $this->seedsPath = $seedsPath;
    }

    /**
     * @return array<int,array{file: string, applied: bool, applied_at: ?string, batch: ?int, modified: bool}>
     */
    public function status(): array
    {
        $this->ensureRepository();
        $applied = $this->appliedMigrations();
        $status = [];
        foreach ($this->files($this->migrationsPath) as $path) {
            $name = basename($path);
            $record = $applied[$name] ?? null;
            $status[] = [
                'file' => $name,
                'applied' => $record !== null,
                'applied_at' => $record !== null ? (string) $record['applied_at'] : null,
                'batch' => $record !== null ? (int) $record['batch'] : null,
                'modified' => $record !== null && $record['checksum'] !== hash_file('sha256', $path),
            ];
        }
        return $status;
    }

    /**
     * @param callable $output function (string $line): void
     * @return int Nombre de migrations appliquées
     */
    public function migrate(callable $output): int
    {
        $this->ensureRepository();
        $applied = $this->appliedMigrations();
        $pending = array_values(array_filter($this->files($this->migrationsPath), function (string $path) use ($applied): bool {
            return !isset($applied[basename($path)]);
        }));
        if ($pending === []) {
            return 0;
        }

        $batch = (int) $this->db->fetchValue('SELECT COALESCE(MAX(batch), 0) + 1 FROM schema_migrations');
        foreach ($pending as $path) {
            $name = basename($path);
            $output('Migration : ' . $name);
            $this->executeFile($path);
            $this->db->insert('schema_migrations', [
                'filename' => $name,
                'checksum' => hash_file('sha256', $path),
                'batch' => $batch,
                'applied_at' => Clock::nowForDatabase(),
            ]);
        }
        return count($pending);
    }

    /**
     * Les seeds sont idempotents : ils sont tous rejoués, chacun dans sa transaction.
     *
     * @param callable $output function (string $line): void
     */
    public function seed(callable $output): int
    {
        $files = $this->files($this->seedsPath);
        foreach ($files as $path) {
            $output('Seed : ' . basename($path));
            $this->db->transaction(function () use ($path): void {
                $this->executeFile($path);
            });
        }
        return count($files);
    }

    /**
     * Supprime toutes les tables de la base configurée. Réservé au développement (contrôlé par la console).
     */
    public function dropAllTables(): void
    {
        $pdo = $this->db->pdo();
        $tables = $this->db->fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $row) {
                $table = (string) array_values($row)[0];
                $pdo->exec('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private function ensureRepository(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                filename   VARCHAR(190) NOT NULL,
                checksum   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                batch      INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL,
                PRIMARY KEY (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<string,array>
     */
    private function appliedMigrations(): array
    {
        $applied = [];
        foreach ($this->db->fetchAll('SELECT filename, checksum, batch, applied_at FROM schema_migrations') as $row) {
            $applied[(string) $row['filename']] = $row;
        }
        return $applied;
    }

    private function executeFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException(sprintf('Lecture impossible : %s', basename($path)));
        }
        foreach (SqlSplitter::split($sql) as $index => $statement) {
            try {
                $this->db->pdo()->exec($statement);
            } catch (\PDOException $exception) {
                throw new \RuntimeException(
                    sprintf('Échec de %s (instruction n° %d) : %s', basename($path), $index + 1, $exception->getMessage()),
                    0,
                    $exception
                );
            }
        }
    }

    /**
     * @return string[]
     */
    private function files(string $directory): array
    {
        $files = glob(rtrim($directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }
}
