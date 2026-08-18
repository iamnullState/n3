<?php
declare(strict_types=1);

namespace N3\Service;

use PDO;
use Throwable;

final class SystemDiagnosticsService
{
    public function __construct(
        private readonly PDO $database,
        private readonly string $dataDirectory,
        private readonly string $backupDirectory,
        private readonly string $appVersion,
    ) {}

    public function report(): array
    {
        return [
            'checked_at' => gmdate(DATE_ATOM),
            'version' => $this->appVersion,
            'storage' => $this->storage(),
            'database' => $this->database(),
            'backup' => $this->backup(),
        ];
    }

    private function storage(): array
    {
        $databasePath = rtrim($this->dataDirectory, '/') . '/n3.sqlite';
        $dataWritable = $this->probeWritableDirectory($this->dataDirectory);
        $databaseWritable = is_file($databasePath) && is_writable($databasePath);
        $free = @disk_free_space($this->dataDirectory);
        $total = @disk_total_space($this->dataDirectory);
        $databaseBytes = @filesize($databasePath);
        $capacityKnown = $free !== false && $total !== false;
        $status = !$dataWritable || !$databaseWritable || ($free !== false && $free <= 0)
            ? 'error'
            : ($capacityKnown ? 'ok' : 'warning');

        return [
            'status' => $status,
            'data_writable' => $dataWritable,
            'database_writable' => $databaseWritable,
            'free_bytes' => $free === false ? null : (int)$free,
            'total_bytes' => $total === false ? null : (int)$total,
            'database_bytes' => $databaseBytes === false ? null : (int)$databaseBytes,
        ];
    }

    private function database(): array
    {
        try {
            $integrity = $this->database->query('PRAGMA integrity_check')->fetchColumn() === 'ok';
            $foreignKeys = $this->database->query('PRAGMA foreign_key_check')->fetch() === false;
            $hasLedger = (bool)$this->database
                ->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")
                ->fetchColumn();
            $schemaVersion = $hasLedger
                ? (int)$this->database->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn()
                : 0;
            return [
                'status' => $integrity && $foreignKeys ? 'ok' : 'error',
                'integrity' => $integrity ? 'ok' : 'error',
                'foreign_keys' => $foreignKeys ? 'ok' : 'error',
                'schema_version' => $schemaVersion,
            ];
        } catch (Throwable) {
            return [
                'status' => 'error',
                'integrity' => 'unavailable',
                'foreign_keys' => 'unavailable',
                'schema_version' => null,
            ];
        }
    }

    private function backup(): array
    {
        if (!is_dir($this->backupDirectory)) {
            return ['status' => 'missing', 'latest_at' => null, 'age_seconds' => null, 'size_bytes' => null];
        }
        if (!is_readable($this->backupDirectory)) {
            return ['status' => 'unavailable', 'latest_at' => null, 'age_seconds' => null, 'size_bytes' => null];
        }

        $latest = null;
        $latestTime = null;
        foreach (glob(rtrim($this->backupDirectory, '/') . '/n3-*.tar.gz') ?: [] as $path) {
            if (!is_file($path)) continue;
            $modified = @filemtime($path);
            if ($modified === false || ($latestTime !== null && $modified <= $latestTime)) continue;
            $latest = $path;
            $latestTime = $modified;
        }
        if ($latest === null || $latestTime === null) {
            return ['status' => 'missing', 'latest_at' => null, 'age_seconds' => null, 'size_bytes' => null];
        }
        $size = @filesize($latest);
        return [
            'status' => 'available',
            'latest_at' => gmdate(DATE_ATOM, $latestTime),
            'age_seconds' => max(0, time() - $latestTime),
            'size_bytes' => $size === false ? null : (int)$size,
        ];
    }

    private function probeWritableDirectory(string $directory): bool
    {
        if (!is_dir($directory) || !is_writable($directory)) return false;
        $probe = @tempnam($directory, '.n3-diagnostics-');
        if ($probe === false) return false;
        $written = @file_put_contents($probe, 'ok', LOCK_EX) === 2;
        $removed = @unlink($probe);
        return $written && $removed;
    }
}
