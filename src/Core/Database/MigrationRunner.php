<?php

declare(strict_types=1);

namespace N3\Core\Database;

use PDO;
use RuntimeException;

final readonly class MigrationRunner
{
    public function __construct(
        private PDO $connection,
        private string $migrationPath,
    ) {
    }

    /** @return list<string> */
    public function migrate(): array
    {
        $this->ensureRepository();
        $migrations = $this->loadMigrations();
        $applied = $this->appliedMigrations();
        $this->verifyChecksums($migrations, $applied);
        $batch = $this->nextBatch();
        $completed = [];

        foreach ($migrations as $version => $definition) {
            if (isset($applied[$version])) {
                continue;
            }

            $definition['migration']->up($this->connection);
            $statement = $this->connection->prepare(
                'INSERT INTO schema_migrations (version, checksum, batch) '
                . 'VALUES (:version, :checksum, :batch)',
            );
            $statement->execute([
                'version' => $version,
                'checksum' => $definition['checksum'],
                'batch' => $batch,
            ]);
            $completed[] = $version;
        }

        return $completed;
    }

    /** @return list<string> */
    public function rollbackLatestBatch(bool $allowDestructive): array
    {
        if (!$allowDestructive) {
            throw new DatabaseException('Migration rollback requires explicit destructive-action approval.');
        }

        if (!$this->repositoryExists()) {
            return [];
        }

        $migrations = $this->loadMigrations();
        $applied = $this->appliedMigrations();
        $this->verifyChecksums($migrations, $applied);
        $batch = $this->latestBatch();

        if ($batch === null) {
            return [];
        }

        $versions = array_keys(array_filter(
            $applied,
            static fn (array $record): bool => $record['batch'] === $batch,
        ));
        rsort($versions, SORT_STRING);
        $rolledBack = [];

        foreach ($versions as $version) {
            $migrations[$version]['migration']->down($this->connection);
            $statement = $this->connection->prepare(
                'DELETE FROM schema_migrations WHERE version = :version',
            );
            $statement->execute(['version' => $version]);
            $rolledBack[] = $version;
        }

        return $rolledBack;
    }

    /** @return list<MigrationStatus> */
    public function status(): array
    {
        $migrations = $this->loadMigrations();
        $applied = $this->repositoryExists() ? $this->appliedMigrations() : [];
        $this->verifyChecksums($migrations, $applied);

        return array_map(
            static fn (string $version): MigrationStatus => new MigrationStatus(
                $version,
                isset($applied[$version]),
            ),
            array_keys($migrations),
        );
    }

    private function ensureRepository(): void
    {
        $this->connection->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version VARCHAR(191) NOT NULL PRIMARY KEY, '
            . 'checksum CHAR(64) NOT NULL, '
            . 'batch INT UNSIGNED NOT NULL, '
            . 'applied_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    private function repositoryExists(): bool
    {
        $statement = $this->connection->query(
            "SELECT COUNT(*) FROM information_schema.tables "
            . "WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'",
        );

        if ($statement === false) {
            throw new RuntimeException('Unable to inspect migration state.');
        }

        return (int) $statement->fetchColumn() === 1;
    }

    /** @return array<string, array{migration: Migration, checksum: string}> */
    private function loadMigrations(): array
    {
        $files = glob(rtrim($this->migrationPath, DIRECTORY_SEPARATOR) . '/*.php');

        if ($files === false) {
            throw new DatabaseException('Unable to read the migration directory.');
        }

        sort($files, SORT_STRING);
        $migrations = [];

        foreach ($files as $file) {
            $version = pathinfo($file, PATHINFO_FILENAME);
            $migration = require $file;

            if (!$migration instanceof Migration || $migration->version() !== $version) {
                throw new DatabaseException(sprintf('Migration %s has an invalid definition.', $version));
            }

            $checksum = hash_file('sha256', $file);

            if ($checksum === false) {
                throw new DatabaseException(sprintf('Unable to checksum migration %s.', $version));
            }

            $migrations[$version] = [
                'migration' => $migration,
                'checksum' => $checksum,
            ];
        }

        return $migrations;
    }

    /** @return array<string, array{checksum: string, batch: int}> */
    private function appliedMigrations(): array
    {
        $statement = $this->connection->query(
            'SELECT version, checksum, batch FROM schema_migrations ORDER BY version',
        );

        if ($statement === false) {
            throw new RuntimeException('Unable to read migration state.');
        }

        $applied = [];

        foreach ($statement->fetchAll() as $row) {
            $applied[(string) $row['version']] = [
                'checksum' => (string) $row['checksum'],
                'batch' => (int) $row['batch'],
            ];
        }

        return $applied;
    }

    /**
     * @param array<string, array{migration: Migration, checksum: string}> $migrations
     * @param array<string, array{checksum: string, batch: int}> $applied
     */
    private function verifyChecksums(array $migrations, array $applied): void
    {
        foreach ($applied as $version => $record) {
            if (!isset($migrations[$version])) {
                throw new DatabaseException(sprintf('Applied migration %s is missing from disk.', $version));
            }

            if (!hash_equals($record['checksum'], $migrations[$version]['checksum'])) {
                throw new DatabaseException(sprintf('Applied migration %s was modified.', $version));
            }
        }
    }

    private function nextBatch(): int
    {
        return ($this->latestBatch() ?? 0) + 1;
    }

    private function latestBatch(): ?int
    {
        $value = $this->connection->query('SELECT MAX(batch) FROM schema_migrations')?->fetchColumn();

        return $value === false || $value === null ? null : (int) $value;
    }
}
