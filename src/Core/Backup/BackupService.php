<?php

declare(strict_types=1);

namespace N3\Core\Backup;

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use N3\Core\Database\DatabaseConfig;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

final readonly class BackupService
{
    private const FORMAT = 'n3-backup-v1';
    private const DURABLE_DIRECTORIES = ['install', 'modules', 'files', 'images', 'videos'];

    public function __construct(
        private string $root,
        private string $appVersion,
        private BackupConfig $config,
        private ?DatabaseConfig $database = null,
        private ?PDO $connection = null,
        private ?DatabaseBackupDriver $driver = null,
        private EncryptedStream $encryptedStream = new EncryptedStream(),
        private ?DateTimeImmutable $clock = null,
    ) {
    }

    /** @return array{id: string, database_tables: int, storage_files: int, plaintext_bytes: int} */
    public function create(): array
    {
        $database = $this->requireDatabase();
        $driver = $this->requireDriver();
        $backupRoot = $this->ensureBackupRoot();
        $now = $this->now();
        $id = $now->format('Ymd\THis\Z') . '-' . bin2hex(random_bytes(6));
        $temporary = $backupRoot . '/.creating-' . substr($id, -12);
        $lock = $this->lock($backupRoot);

        try {
            if (!mkdir($temporary . '/objects', 0700, true)) {
                throw new BackupException('Unable to create a private backup staging directory.');
            }
            @chmod($temporary, 0700);
            $tables = $this->existingManagedTables();
            if (array_diff($database->tableNames->coreTables(), $tables) !== []) {
                throw new BackupException('Required Core tables are missing; the installation cannot be backed up safely.');
            }
            if (!$this->installationComplete()) {
                throw new BackupException('Only a completed installation can be backed up.');
            }
            $installationLock = $this->root . '/storage/install/installed.lock';
            if (!is_file($installationLock) || is_link($installationLock)) {
                throw new BackupException('The private installation lock is required for a coordinated backup.');
            }
            $databaseArtifact = $this->encryptedStream->encrypt(
                $driver->export($database, $tables),
                $temporary . '/database.n3enc',
                $this->config->encryptionKey(),
                $id . "\0database",
            );
            $storage = [];
            $plaintextBytes = $databaseArtifact['bytes'];
            foreach ($this->durableFiles() as $index => $entry) {
                $object = sprintf('objects/%06d.n3enc', $index + 1);
                $artifact = $this->encryptedStream->encrypt(
                    $this->fileChunks($entry['absolute']),
                    $temporary . '/' . $object,
                    $this->config->encryptionKey(),
                    $id . "\0storage/" . $entry['relative'],
                );
                $storage[] = [
                    'path' => $entry['relative'],
                    'object' => $object,
                    'sha256' => $artifact['sha256'],
                    'bytes' => $artifact['bytes'],
                ];
                $plaintextBytes += $artifact['bytes'];
            }
            $manifest = [
                'format' => self::FORMAT,
                'id' => $id,
                'created_at' => $now->format(DATE_ATOM),
                'app_version' => $this->appVersion,
                'source_database' => $database->database,
                'table_prefix' => $database->tableNames->prefix(),
                'database' => [
                    'object' => 'database.n3enc',
                    'sha256' => $databaseArtifact['sha256'],
                    'bytes' => $databaseArtifact['bytes'],
                    'tables' => $tables,
                ],
                'storage' => $storage,
            ];
            $json = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
            $this->writePrivateFile($temporary . '/manifest.json', $json);
            $this->writePrivateFile(
                $temporary . '/manifest.hmac',
                hash_hmac('sha256', $json, $this->config->authenticationKey()) . "\n",
            );
            if (!rename($temporary, $backupRoot . '/' . $id)) {
                throw new BackupException('Unable to finalize the backup bundle.');
            }

            return [
                'id' => $id,
                'database_tables' => count($tables),
                'storage_files' => count($storage),
                'plaintext_bytes' => $plaintextBytes,
            ];
        } catch (Throwable $exception) {
            $this->removeTree($temporary);
            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException('Backup creation failed safely.', previous: $exception);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return array<string, mixed> */
    public function verify(string $id): array
    {
        $directory = $this->bundleDirectory($id);
        $json = $this->readSmallFile($directory . '/manifest.json', 1048576);
        $signature = trim($this->readSmallFile($directory . '/manifest.hmac', 128));
        $expected = hash_hmac('sha256', $json, $this->config->authenticationKey());
        if (strlen($signature) !== 64 || !hash_equals($expected, $signature)) {
            throw new BackupException('Backup manifest authentication failed.');
        }
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT || ($manifest['id'] ?? null) !== $id) {
            throw new BackupException('Backup manifest format or identity is invalid.');
        }
        $database = $manifest['database'] ?? null;
        if (!is_array($database) || ($database['object'] ?? null) !== 'database.n3enc'
            || !is_array($database['tables'] ?? null)) {
            throw new BackupException('Backup database manifest is invalid.');
        }
        $prefix = $manifest['table_prefix'] ?? null;
        if (!is_string($prefix)) {
            throw new BackupException('Backup table-prefix identity is invalid.');
        }
        try {
            $knownTables = (new \N3\Core\Database\TableNames($prefix))->managedTables();
        } catch (Throwable $exception) {
            throw new BackupException('Backup table-prefix identity is invalid.', previous: $exception);
        }
        $manifestTables = $database['tables'];
        if (array_filter($manifestTables, 'is_string') !== $manifestTables
            || array_values(array_unique($manifestTables, SORT_STRING)) !== $manifestTables
            || array_diff($manifestTables, $knownTables) !== []) {
            throw new BackupException('Backup database table inventory is invalid.');
        }
        $this->verifyArtifact($directory . '/database.n3enc', $database, $id . "\0database");
        $seenPaths = [];
        $seenObjects = [];
        $storage = $manifest['storage'] ?? null;
        if (!is_array($storage)) {
            throw new BackupException('Backup storage manifest is invalid.');
        }
        foreach ($storage as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null) || !is_string($entry['object'] ?? null)) {
                throw new BackupException('Backup storage entry is invalid.');
            }
            $path = $entry['path'];
            $object = $entry['object'];
            $this->assertDurableRelativePath($path);
            if (preg_match('#^objects/[0-9]{6}\.n3enc$#D', $object) !== 1
                || isset($seenPaths[$path]) || isset($seenObjects[$object])) {
                throw new BackupException('Backup storage inventory is invalid.');
            }
            $seenPaths[$path] = true;
            $seenObjects[$object] = true;
            $this->verifyArtifact($directory . '/' . $object, $entry, $id . "\0storage/" . $path);
        }

        return $manifest;
    }

    /** @return array{database_tables: int, storage_files: int, source_database: string, table_prefix: string} */
    public function restore(string $id, string $storageTarget, bool $apply): array
    {
        $databaseConfig = $this->requireDatabase();
        $driver = $this->requireDriver();
        $manifest = $this->verify($id);
        $database = $manifest['database'];
        $storage = $manifest['storage'];
        if (($manifest['table_prefix'] ?? null) !== $databaseConfig->tableNames->prefix()) {
            throw new BackupException('Backup table prefix does not match the clean restore target.');
        }
        if ($this->existingManagedTables() !== []) {
            throw new BackupException('Restore requires a clean target with no managed N3 tables.');
        }
        $target = $this->validateStorageTarget($storageTarget);
        $summary = [
            'database_tables' => count($database['tables']),
            'storage_files' => count($storage),
            'source_database' => (string) ($manifest['source_database'] ?? ''),
            'table_prefix' => (string) ($manifest['table_prefix'] ?? ''),
        ];
        if (!$apply) {
            return $summary;
        }

        $createdFiles = [];
        $createdDirectories = [];
        try {
            if (!is_dir($target)) {
                if (!mkdir($target, 0700, true)) {
                    throw new BackupException('Unable to create the restore storage target.');
                }
                $createdDirectories[] = $target;
            }
            @chmod($target, 0700);
            $bundle = $this->bundleDirectory($id);
            foreach ($storage as $entry) {
                $destination = $target . '/' . $entry['path'];
                $parent = dirname($destination);
                $this->ensureRestoreDirectory($parent, $target, $createdDirectories);
                if (file_exists($destination) || is_link($destination)) {
                    throw new BackupException('Restore refuses to overwrite an existing private file.');
                }
                $handle = @fopen($destination, 'xb');
                if (!is_resource($handle)) {
                    throw new BackupException('Unable to create a restored private file.');
                }
                try {
                    foreach ($this->encryptedStream->decrypt(
                        $bundle . '/' . $entry['object'],
                        $this->config->encryptionKey(),
                        $id . "\0storage/" . $entry['path'],
                    ) as $chunk) {
                        $this->writeAll($handle, $chunk);
                    }
                } finally {
                    fclose($handle);
                }
                @chmod($destination, 0600);
                $createdFiles[] = $destination;
            }
            $driver->import(
                $databaseConfig,
                $this->encryptedStream->decrypt(
                    $bundle . '/database.n3enc',
                    $this->config->encryptionKey(),
                    $id . "\0database",
                ),
            );
            $restored = $this->existingManagedTables();
            $expectedTables = $database['tables'];
            sort($restored, SORT_STRING);
            sort($expectedTables, SORT_STRING);
            if ($restored !== $expectedTables) {
                throw new BackupException('Restored database table inventory does not match the bundle.');
            }
            if (!$this->installationComplete() || !is_file($target . '/install/installed.lock')) {
                throw new BackupException('Restored installation state and private lock are incomplete.');
            }
        } catch (Throwable $exception) {
            foreach (array_reverse($createdFiles) as $file) {
                @unlink($file);
            }
            foreach (array_reverse($createdDirectories) as $directory) {
                @rmdir($directory);
            }
            if ($exception instanceof BackupException) {
                throw $exception;
            }
            throw new BackupException('Restore failed safely.', previous: $exception);
        }

        return $summary;
    }

    /** @return list<string> */
    public function pruneCandidates(?int $retentionDays = null): array
    {
        $days = $retentionDays ?? $this->config->retentionDays;
        if ($days < 1 || $days > 3650) {
            throw new BackupException('Backup retention must be between 1 and 3650 days.');
        }
        $cutoff = $this->now()->modify('-' . $days . ' days');
        $candidates = [];
        foreach (scandir($this->ensureBackupRoot()) ?: [] as $id) {
            if (!$this->validId($id)) {
                continue;
            }
            $created = DateTimeImmutable::createFromFormat('!Ymd\THis\Z', substr($id, 0, 16), new DateTimeZone('UTC'));
            if ($created instanceof DateTimeImmutable && $created < $cutoff) {
                $this->verify($id);
                $candidates[] = $id;
            }
        }
        sort($candidates, SORT_STRING);

        return $candidates;
    }

    /** @param list<string> $ids */
    public function prune(array $ids): int
    {
        $backupRoot = $this->ensureBackupRoot();
        $lock = $this->lock($backupRoot);
        try {
            foreach ($ids as $id) {
                $this->verify($id);
            }
            foreach ($ids as $id) {
                $this->removeTree($this->bundleDirectory($id));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return count($ids);
    }

    /** @return list<string> */
    private function existingManagedTables(): array
    {
        $database = $this->requireDatabase();
        $rows = $this->requireConnection()->query(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = \'BASE TABLE\'',
        )?->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $existing = array_fill_keys(array_filter($rows, 'is_string'), true);
        $managed = array_values(array_filter(
            $database->tableNames->managedTables(),
            static fn (string $table): bool => isset($existing[$table]),
        ));
        sort($managed, SORT_STRING);

        return $managed;
    }

    /** @return list<array{absolute: string, relative: string}> */
    private function durableFiles(): array
    {
        $files = [];
        $installationLock = $this->root . '/storage/install/installed.lock';
        if (is_file($installationLock) && !is_link($installationLock)) {
            $files[] = ['absolute' => $installationLock, 'relative' => 'install/installed.lock'];
        }
        foreach (['modules', 'files', 'images', 'videos'] as $directory) {
            $base = $this->root . '/storage/' . $directory;
            if (!is_dir($base)) {
                continue;
            }
            if (is_link($base)) {
                throw new BackupException('Durable storage directories must not be symbolic links.');
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    throw new BackupException('Durable storage must not contain symbolic links.');
                }
                if (!$item->isFile()) {
                    continue;
                }
                $absolute = $item->getPathname();
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($this->root . '/storage/')));
                $this->assertDurableRelativePath($relative);
                $files[] = ['absolute' => $absolute, 'relative' => $relative];
            }
        }
        usort($files, static fn (array $left, array $right): int => strcmp($left['relative'], $right['relative']));

        return $files;
    }

    private function installationComplete(): bool
    {
        $table = $this->requireDatabase()->tableNames->physical('installation_state');
        $statement = $this->requireConnection()->query(
            sprintf('SELECT install_status FROM `%s` WHERE id = 1 LIMIT 1', $table),
        );

        return $statement?->fetchColumn() === 'complete';
    }

    private function requireDatabase(): DatabaseConfig
    {
        if (!$this->database instanceof DatabaseConfig) {
            throw new BackupException('This backup operation requires database configuration.');
        }

        return $this->database;
    }

    private function requireConnection(): PDO
    {
        if (!$this->connection instanceof PDO) {
            throw new BackupException('This backup operation requires a database connection.');
        }

        return $this->connection;
    }

    private function requireDriver(): DatabaseBackupDriver
    {
        if (!$this->driver instanceof DatabaseBackupDriver) {
            throw new BackupException('This backup operation requires a database backup driver.');
        }

        return $this->driver;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** @return iterable<string> */
    private function fileChunks(string $file): iterable
    {
        $handle = @fopen($file, 'rb');
        if (!is_resource($handle)) {
            throw new BackupException('Unable to read a durable private file.');
        }
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 65536);
                if ($chunk === false) {
                    throw new BackupException('Unable to read a durable private file.');
                }
                if ($chunk !== '') {
                    yield $chunk;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $entry */
    private function verifyArtifact(string $file, array $entry, string $associatedData): void
    {
        if (!is_string($entry['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $entry['sha256']) !== 1
            || !is_int($entry['bytes'] ?? null) || $entry['bytes'] < 0) {
            throw new BackupException('Backup artifact metadata is invalid.');
        }
        $hash = hash_init('sha256');
        $bytes = 0;
        foreach ($this->encryptedStream->decrypt($file, $this->config->encryptionKey(), $associatedData) as $chunk) {
            hash_update($hash, $chunk);
            $bytes += strlen($chunk);
        }
        if ($bytes !== $entry['bytes'] || !hash_equals($entry['sha256'], hash_final($hash))) {
            throw new BackupException('Backup artifact integrity verification failed.');
        }
    }

    private function ensureBackupRoot(): string
    {
        $path = rtrim($this->config->path, '/');
        if (is_link($path)) {
            throw new BackupException('BACKUP_PATH must not be a symbolic link.');
        }
        if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
            throw new BackupException('Unable to create BACKUP_PATH.');
        }
        @chmod($path, 0700);
        $real = realpath($path);
        $public = realpath($this->root . '/public');
        if ($real === false || $real === DIRECTORY_SEPARATOR || !is_writable($real)
            || ($public !== false && ($real === $public || str_starts_with($real . '/', $public . '/')))) {
            throw new BackupException('BACKUP_PATH must be writable and outside public/.');
        }

        return $real;
    }

    /** @return resource */
    private function lock(string $backupRoot)
    {
        $handle = @fopen($backupRoot . '/.backup.lock', 'c+b');
        if (!is_resource($handle) || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new BackupException('Another backup operation is already running.');
        }
        @chmod($backupRoot . '/.backup.lock', 0600);

        return $handle;
    }

    private function bundleDirectory(string $id): string
    {
        if (!$this->validId($id)) {
            throw new BackupException('Backup identifier is invalid.');
        }
        $directory = $this->ensureBackupRoot() . '/' . $id;
        if (!is_dir($directory) || is_link($directory)) {
            throw new BackupException('Backup bundle does not exist or is unsafe.');
        }

        return $directory;
    }

    private function validId(string $id): bool
    {
        return preg_match('/^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{12}$/D', $id) === 1;
    }

    private function assertDurableRelativePath(string $path): void
    {
        $top = explode('/', $path, 2)[0];
        if ($path === '' || strlen($path) > 1024 || !in_array($top, self::DURABLE_DIRECTORIES, true)
            || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('#(?:^|/)\.\.?(/|$)#', $path) === 1) {
            throw new BackupException('Backup contains an unsafe private-storage path.');
        }
    }

    private function validateStorageTarget(string $path): string
    {
        if ($path === '' || $path[0] !== '/' || rtrim($path, '/') === '' || str_contains($path, "\0") || is_link($path)) {
            throw new BackupException('Restore storage target must be an absolute non-symlink path.');
        }
        $normalized = rtrim($path, '/');
        $public = realpath($this->root . '/public');
        $parent = realpath(is_dir($normalized) ? $normalized : dirname($normalized));
        if ($parent === false || $parent === DIRECTORY_SEPARATOR
            || ($public !== false && ($parent === $public || str_starts_with($parent . '/', $public . '/')))) {
            throw new BackupException('Restore storage target must exist under a private parent outside public/.');
        }
        foreach (self::DURABLE_DIRECTORIES as $directory) {
            if (file_exists($normalized . '/' . $directory) || is_link($normalized . '/' . $directory)) {
                throw new BackupException('Restore storage target already contains durable N3 data.');
            }
        }

        return $normalized;
    }

    /** @param list<string> $createdDirectories */
    private function ensureRestoreDirectory(string $directory, string $target, array &$createdDirectories): void
    {
        if (is_dir($directory) && !is_link($directory)) {
            return;
        }
        $missing = [];
        $cursor = $directory;
        while ($cursor !== $target && !is_dir($cursor)) {
            if (is_link($cursor) || !str_starts_with($cursor . '/', $target . '/')) {
                throw new BackupException('Restore directory path is unsafe.');
            }
            $missing[] = $cursor;
            $cursor = dirname($cursor);
        }
        foreach (array_reverse($missing) as $path) {
            if (!mkdir($path, 0700) || !chmod($path, 0700)) {
                throw new BackupException('Unable to create a restore directory.');
            }
            $createdDirectories[] = $path;
        }
    }

    private function readSmallFile(string $file, int $limit): string
    {
        if (!is_file($file) || is_link($file) || filesize($file) === false || filesize($file) > $limit) {
            throw new BackupException('Backup metadata file is missing or unsafe.');
        }
        $contents = file_get_contents($file);
        if (!is_string($contents)) {
            throw new BackupException('Unable to read backup metadata.');
        }

        return $contents;
    }

    private function writePrivateFile(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if (!is_resource($handle)) {
            throw new BackupException('Unable to create private backup metadata.');
        }
        try {
            $this->writeAll($handle, $contents);
        } finally {
            fclose($handle);
        }
        @chmod($path, 0600);
    }

    /** @param resource $handle */
    private function writeAll($handle, string $contents): void
    {
        while ($contents !== '') {
            $written = fwrite($handle, $contents);
            if ($written === false || $written === 0) {
                throw new BackupException('Unable to write a private backup file.');
            }
            $contents = substr($contents, $written);
        }
    }

    private function removeTree(string $path): void
    {
        if ($path === '' || !str_starts_with($path, $this->ensureBackupRoot() . '/') || is_link($path)) {
            return;
        }
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($path);
    }
}
