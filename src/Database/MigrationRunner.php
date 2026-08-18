<?php
declare(strict_types=1);

namespace N3\Database;

use PDO;
use RuntimeException;
use Throwable;

final class MigrationRunner
{
    public function __construct(
        private readonly string $directory,
        private readonly string $dataDirectory,
        private readonly string $appVersion,
    ) {}

    public function run(PDO $database, bool $databaseExisted): void
    {
        $ledgerExists = (bool)$database->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetchColumn();
        if ($databaseExisted && !$ledgerExists) $this->snapshot($database);
        $database->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS schema_migrations (
                version INTEGER PRIMARY KEY,
                name TEXT NOT NULL,
                app_version TEXT NOT NULL,
                applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        SQL);
        $database->exec("INSERT OR IGNORE INTO schema_migrations (version, name, app_version) VALUES (0, 'pre-ledger baseline', '0.1.0')");

        $migrations = $this->discover();
        $applied = array_map('intval', $database->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN));
        $supported = $migrations === [] ? 0 : max(array_column($migrations, 'version'));
        if ($applied !== [] && max($applied) > $supported) {
            throw new RuntimeException('This database was created by a newer version of n3.');
        }
        $recorded = array_values(array_filter($applied, static fn(int $version): bool => $version > 0));
        sort($recorded);
        if ($recorded !== [] && $recorded !== range(1, max($recorded))) {
            throw new RuntimeException('The database migration ledger contains a version gap.');
        }
        $pending = array_filter($migrations, static fn(array $migration): bool => !in_array($migration['version'], $applied, true));
        if ($pending === []) return;

        if ($databaseExisted && $ledgerExists) $this->snapshot($database);

        $database->exec('BEGIN IMMEDIATE');
        try {
            $record = $database->prepare('INSERT INTO schema_migrations (version, name, app_version) VALUES (?, ?, ?)');
            foreach ($pending as $migration) {
                ($migration['up'])($database);
                $record->execute([$migration['version'], $migration['name'], $migration['app_version'] ?: $this->appVersion]);
            }
            $violations = $database->query('PRAGMA foreign_key_check')->fetchAll();
            if ($violations !== []) throw new RuntimeException('A database migration introduced a foreign-key violation.');
            $database->exec('COMMIT');
        } catch (Throwable $error) {
            try { $database->exec('ROLLBACK'); } catch (Throwable) {}
            throw $error;
        }
    }

    private function discover(): array
    {
        $files = glob(rtrim($this->directory, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        $migrations = [];
        $expected = 1;
        foreach ($files as $file) {
            if (!preg_match('/\/(\d{3})_[a-z0-9_]+\.php$/D', $file, $match)) {
                throw new RuntimeException('Invalid migration filename: ' . basename($file));
            }
            $version = (int)$match[1];
            if ($version !== $expected) throw new RuntimeException("Database migration sequence expected $expected and found $version.");
            $migration = require $file;
            if (!is_array($migration) || !isset($migration['name'], $migration['up']) || !is_callable($migration['up'])) {
                throw new RuntimeException('Invalid migration definition: ' . basename($file));
            }
            $migrations[] = [
                'version' => $version,
                'name' => (string)$migration['name'],
                'app_version' => (string)($migration['app_version'] ?? $this->appVersion),
                'up' => $migration['up'],
            ];
            $expected++;
        }
        return $migrations;
    }

    private function snapshot(PDO $database): void
    {
        if (!is_dir($this->dataDirectory) && !mkdir($this->dataDirectory, 0775, true) && !is_dir($this->dataDirectory)) {
            throw new RuntimeException('Could not create the migration snapshot directory.');
        }
        $path = rtrim($this->dataDirectory, '/') . '/n3-pre-migration-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.sqlite';
        $quoted = $database->quote($path);
        $database->exec('VACUUM INTO ' . $quoted);
        chmod($path, 0600);
    }
}
