<?php
declare(strict_types=1);

function backupDatabasePath(?string $dataDir = null): string
{
    return rtrim($dataDir ?? (getenv('DATA_DIR') ?: '/var/www/data'), '/') . '/n3.sqlite';
}

function openBackupDatabase(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
    return $pdo;
}

function backupAppVersion(): string
{
    $path = dirname(__DIR__) . '/VERSION';
    return is_file($path) ? trim((string)file_get_contents($path)) : 'unknown';
}

function supportedSchemaVersion(): int
{
    $versions = [];
    foreach (glob(dirname(__DIR__) . '/database/migrations/*.php') ?: [] as $file) {
        if (preg_match('/^(\d{3})_/', basename($file), $match)) $versions[] = (int)$match[1];
    }
    return $versions === [] ? 0 : max($versions);
}

function databaseSchemaVersion(PDO $pdo): int
{
    $ledger = (bool)$pdo->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'schema_migrations'")->fetchColumn();
    return $ledger ? (int)$pdo->query('SELECT COALESCE(MAX(version), 0) FROM schema_migrations')->fetchColumn() : 0;
}

function databaseAvatarReferences(PDO $pdo): array
{
    $columns = $pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('avatar_reference', $columns, true)) return [];
    $references = $pdo->query("SELECT DISTINCT avatar_reference FROM users WHERE avatar_reference IS NOT NULL AND avatar_reference != '' ORDER BY avatar_reference")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($references as $reference) {
        if (!is_string($reference) || !preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp)$/D', $reference)) {
            throw new RuntimeException('Database contains an invalid avatar reference.');
        }
    }
    return $references;
}

function pluginMediaFiles(string $dataDir): array
{
    $plugins = [];
    $root = rtrim($dataDir, '/') . '/plugin-media';
    foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $pluginId = basename($directory);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $pluginId)) continue;
        $files = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            $filename = basename($path);
            if (is_file($path) && preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp|mp4|webm|mov)$/D', $filename)) {
                $files[$filename] = hash_file('sha256', $path);
            }
        }
        if ($files !== []) {
            ksort($files);
            $plugins[$pluginId] = $files;
        }
    }
    ksort($plugins);
    return $plugins;
}

function validateDatabase(string $path, ?array $manifest = null): array
{
    if (!is_file($path) || filesize($path) < 100) throw new RuntimeException('Backup does not contain a usable SQLite database.');
    $pdo = openBackupDatabase($path);
    if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') throw new RuntimeException('SQLite integrity check failed.');
    $required = ['spaces', 'pages', 'users', 'tags', 'page_tags', 'page_revisions'];
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($required as $table) if (!in_array($table, $tables, true)) throw new RuntimeException("Required table is missing: $table");
    $schemaVersion = databaseSchemaVersion($pdo);
    if ($schemaVersion > supportedSchemaVersion()) throw new RuntimeException('Database uses a newer unsupported schema.');
    $counts = [
        'spaces' => (int)$pdo->query('SELECT COUNT(*) FROM spaces')->fetchColumn(),
        'pages' => (int)$pdo->query('SELECT COUNT(*) FROM pages')->fetchColumn(),
        'tags' => (int)$pdo->query('SELECT COUNT(*) FROM tags')->fetchColumn(),
        'revisions' => (int)$pdo->query('SELECT COUNT(*) FROM page_revisions')->fetchColumn(),
        'users' => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    ];
    if ($manifest !== null && ($manifest['counts'] ?? null) !== $counts) throw new RuntimeException('Archive counts do not match its manifest.');
    if ($manifest !== null && isset($manifest['schema_version']) && (int)$manifest['schema_version'] !== $schemaVersion) throw new RuntimeException('Archive schema version does not match its database.');
    return $counts;
}

function createBackup(string $outputDir, ?string $dataDir = null, ?int $retention = null): string
{
    $database = backupDatabasePath($dataDir);
    if (!is_file($database)) throw new RuntimeException("Database not found: $database");
    if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir)) throw new RuntimeException("Cannot create backup directory: $outputDir");
    $timestamp = gmdate('Ymd-His');
    $base = rtrim($outputDir, '/') . "/n3-$timestamp-" . bin2hex(random_bytes(3));
    $snapshot = $base . '.sqlite.tmp';
    $tar = $base . '.tar';
    $archive = $tar . '.gz';
    try {
        $pdo = openBackupDatabase($database);
        $pdo->exec('PRAGMA wal_checkpoint(PASSIVE)');
        $quoted = str_replace("'", "''", $snapshot);
        $pdo->exec("VACUUM INTO '$quoted'");
        chmod($snapshot, 0600);
        $counts = validateDatabase($snapshot);
        $mediaFiles = [];
        $mediaDirectory = rtrim(dirname($database), '/') . '/media';
        foreach (glob($mediaDirectory . '/*') ?: [] as $mediaPath) {
            $mediaName = basename($mediaPath);
            if (is_file($mediaPath) && preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp|mp4)$/D', $mediaName)) {
                $mediaFiles[$mediaName] = hash_file('sha256', $mediaPath);
            }
        }
        $avatarFiles = [];
        $avatarDirectory = rtrim(dirname($database), '/') . '/avatars';
        $snapshotDatabase = openBackupDatabase($snapshot);
        foreach (databaseAvatarReferences($snapshotDatabase) as $avatarName) {
            $avatarPath = $avatarDirectory . '/' . $avatarName;
            if (!is_file($avatarPath)) throw new RuntimeException('A referenced avatar file is missing.');
            $avatarFiles[$avatarName] = hash_file('sha256', $avatarPath);
        }
        unset($snapshotDatabase);
        $pluginMedia = pluginMediaFiles(dirname($database));
        $manifest = [
            'format' => 'n3-backup',
            'version' => 1,
            'created_at' => gmdate(DATE_ATOM),
            'app_name' => getenv('APP_NAME') ?: 'n3',
            'app_version' => backupAppVersion(),
            'schema_version' => databaseSchemaVersion($pdo),
            'database_file' => 'n3.sqlite',
            'database_sha256' => hash_file('sha256', $snapshot),
            'media' => $mediaFiles,
            'avatars' => $avatarFiles,
            'plugin_media' => $pluginMedia,
            'counts' => $counts,
        ];
        $phar = new PharData($tar);
        $phar->addFile($snapshot, 'n3.sqlite');
        foreach ($mediaFiles as $mediaName => $_hash) $phar->addFile($mediaDirectory . '/' . $mediaName, 'media/' . $mediaName);
        foreach ($avatarFiles as $avatarName => $_hash) $phar->addFile($avatarDirectory . '/' . $avatarName, 'avatars/' . $avatarName);
        foreach ($pluginMedia as $pluginId => $files) {
            foreach ($files as $filename => $_hash) {
                $phar->addFile(dirname($database) . '/plugin-media/' . $pluginId . '/' . $filename, 'plugin-media/' . $pluginId . '/' . $filename);
            }
        }
        $phar->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        unset($phar);
        $input = fopen($tar, 'rb');
        $output = gzopen($archive, 'wb9');
        if ($input === false || $output === false) throw new RuntimeException('Could not compress the backup archive.');
        while (!feof($input)) gzwrite($output, (string)fread($input, 1024 * 1024));
        fclose($input);
        gzclose($output);
        chmod($archive, 0600);
        if ($retention !== null && $retention > 0) rotateBackups($outputDir, $retention);
        return $archive;
    } finally {
        if (is_file($snapshot)) unlink($snapshot);
        if (is_file($tar)) unlink($tar);
    }
}

function inspectArchive(string $archive): array
{
    if (!is_file($archive)) throw new RuntimeException("Backup archive not found: $archive");
    $manifestRaw = @file_get_contents('phar://' . $archive . '/manifest.json');
    if ($manifestRaw === false) throw new RuntimeException('Backup manifest is missing.');
    $manifest = json_decode($manifestRaw, true);
    if (!is_array($manifest)) throw new RuntimeException('Unsupported backup manifest.');
    $format = $manifest['format'] ?? null;
    if (!is_string($format) || !preg_match('/^[a-z][a-z0-9-]*-backup$/D', $format) || ($manifest['version'] ?? 0) !== 1) throw new RuntimeException('Unsupported backup manifest.');
    return $manifest;
}

function extractValidatedArchive(string $archive, string $workDir): array
{
    $manifest = inspectArchive($archive);
    if (isset($manifest['schema_version']) && (int)$manifest['schema_version'] > supportedSchemaVersion()) throw new RuntimeException('Backup uses a newer unsupported database schema.');
    if (!mkdir($workDir, 0700, true) && !is_dir($workDir)) throw new RuntimeException('Cannot create restore workspace.');
    $databaseFile = $manifest['database_file'] ?? null;
    if (!is_string($databaseFile) || basename($databaseFile) !== $databaseFile || !preg_match('/^[a-z0-9._-]+\.sqlite$/D', $databaseFile)) throw new RuntimeException('Backup database filename is invalid.');
    $phar = new PharData($archive);
    if (!$phar->offsetExists($databaseFile)) throw new RuntimeException('Backup database is missing.');
    $phar->extractTo($workDir, [$databaseFile], false);
    $database = $workDir . '/' . $databaseFile;
    if (!hash_equals((string)$manifest['database_sha256'], hash_file('sha256', $database))) throw new RuntimeException('Backup checksum validation failed.');
    validateDatabase($database, $manifest);
    $media = $manifest['media'] ?? [];
    if (!is_array($media)) throw new RuntimeException('Backup media manifest is invalid.');
    foreach ($media as $filename => $checksum) {
        if (!is_string($filename) || !is_string($checksum) || !preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp|mp4)$/D', $filename) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
            throw new RuntimeException('Backup media manifest is invalid.');
        }
        $archivePath = 'media/' . $filename;
        if (!$phar->offsetExists($archivePath)) throw new RuntimeException('Backup media file is missing.');
        $phar->extractTo($workDir, [$archivePath], false);
        if (!hash_equals($checksum, hash_file('sha256', $workDir . '/' . $archivePath))) throw new RuntimeException('Backup media checksum validation failed.');
    }
    $avatars = $manifest['avatars'] ?? [];
    if (!is_array($avatars)) throw new RuntimeException('Backup avatar manifest is invalid.');
    $databaseReferences = databaseAvatarReferences(openBackupDatabase($database));
    $manifestReferences = array_keys($avatars);
    sort($manifestReferences);
    if ($manifestReferences !== $databaseReferences) throw new RuntimeException('Backup avatar manifest does not match its database.');
    foreach ($avatars as $filename => $checksum) {
        if (!is_string($filename) || !is_string($checksum) || !preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp)$/D', $filename) || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
            throw new RuntimeException('Backup avatar manifest is invalid.');
        }
        $archivePath = 'avatars/' . $filename;
        if (!$phar->offsetExists($archivePath)) throw new RuntimeException('Backup avatar file is missing.');
        $phar->extractTo($workDir, [$archivePath], false);
        if (!hash_equals($checksum, hash_file('sha256', $workDir . '/' . $archivePath))) throw new RuntimeException('Backup avatar checksum validation failed.');
    }
    $pluginMedia = $manifest['plugin_media'] ?? [];
    if (!is_array($pluginMedia)) throw new RuntimeException('Backup plugin media manifest is invalid.');
    foreach ($pluginMedia as $pluginId => $files) {
        if (!is_string($pluginId) || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $pluginId) || !is_array($files)) {
            throw new RuntimeException('Backup plugin media manifest is invalid.');
        }
        foreach ($files as $filename => $checksum) {
            if (!is_string($filename) || !is_string($checksum)
                || !preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp|mp4|webm|mov)$/D', $filename)
                || !preg_match('/^[a-f0-9]{64}$/D', $checksum)) {
                throw new RuntimeException('Backup plugin media manifest is invalid.');
            }
            $archivePath = 'plugin-media/' . $pluginId . '/' . $filename;
            if (!$phar->offsetExists($archivePath)) throw new RuntimeException('Backup plugin media file is missing.');
            $phar->extractTo($workDir, [$archivePath], false);
            if (!hash_equals($checksum, hash_file('sha256', $workDir . '/' . $archivePath))) {
                throw new RuntimeException('Backup plugin media checksum validation failed.');
            }
        }
    }
    return [$database, $manifest];
}

function restoreBackup(string $archive, ?string $dataDir = null): array
{
    $dataDir = rtrim($dataDir ?? (getenv('DATA_DIR') ?: '/var/www/data'), '/');
    if (!is_dir($dataDir) && !mkdir($dataDir, 0770, true) && !is_dir($dataDir)) throw new RuntimeException("Cannot create data directory: $dataDir");
    $workDir = $dataDir . '/.restore-' . bin2hex(random_bytes(6));
    try {
        [$validated, $manifest] = extractValidatedArchive($archive, $workDir);
        $target = backupDatabasePath($dataDir);
        if (is_file($target)) {
            try { openBackupDatabase($target)->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable) {}
            $fallback = $dataDir . '/n3-pre-restore-' . gmdate('Ymd-His') . '.sqlite';
            if (!copy($target, $fallback)) throw new RuntimeException('Could not create the pre-restore safety copy.');
            chmod($fallback, 0600);
        }
        $replacement = $dataDir . '/.n3-restored.sqlite';
        if (!copy($validated, $replacement)) throw new RuntimeException('Could not stage the restored database.');
        chmod($replacement, 0660);
        foreach ([$target . '-wal', $target . '-shm'] as $sidecar) if (is_file($sidecar)) unlink($sidecar);
        if (!rename($replacement, $target)) throw new RuntimeException('Could not install the restored database.');
        foreach (array_keys($manifest['media'] ?? []) as $filename) {
            $mediaDirectory = $dataDir . '/media';
            if (!is_dir($mediaDirectory) && !mkdir($mediaDirectory, 0770, true) && !is_dir($mediaDirectory)) throw new RuntimeException('Could not restore the media directory.');
            $mediaTarget = $mediaDirectory . '/' . $filename;
            if (!copy($workDir . '/media/' . $filename, $mediaTarget)) throw new RuntimeException('Could not restore a media file.');
            chmod($mediaTarget, 0660);
        }
        foreach (array_keys($manifest['avatars'] ?? []) as $filename) {
            $avatarDirectory = $dataDir . '/avatars';
            if (!is_dir($avatarDirectory) && !mkdir($avatarDirectory, 0770, true) && !is_dir($avatarDirectory)) throw new RuntimeException('Could not restore the avatar directory.');
            $avatarTarget = $avatarDirectory . '/' . $filename;
            if (!copy($workDir . '/avatars/' . $filename, $avatarTarget)) throw new RuntimeException('Could not restore an avatar file.');
            chmod($avatarTarget, 0660);
        }
        foreach (($manifest['plugin_media'] ?? []) as $pluginId => $files) {
            $pluginMediaDirectory = $dataDir . '/plugin-media/' . $pluginId;
            if (!is_dir($pluginMediaDirectory) && !mkdir($pluginMediaDirectory, 0770, true) && !is_dir($pluginMediaDirectory)) {
                throw new RuntimeException('Could not restore a plugin media directory.');
            }
            foreach (array_keys($files) as $filename) {
                $pluginMediaTarget = $pluginMediaDirectory . '/' . $filename;
                if (!copy($workDir . '/plugin-media/' . $pluginId . '/' . $filename, $pluginMediaTarget)) {
                    throw new RuntimeException('Could not restore a plugin media file.');
                }
                chmod($pluginMediaTarget, 0660);
            }
        }
        validateDatabase($target, $manifest);
        rotateFallbacks($dataDir, 3);
        return $manifest;
    } finally {
        removeDirectory($workDir);
    }
}

function rotateBackups(string $directory, int $keep): void
{
    $files = glob(rtrim($directory, '/') . '/n3-*.tar.gz') ?: [];
    usort($files, fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $file) unlink($file);
}

function rotateFallbacks(string $directory, int $keep): void
{
    $files = glob(rtrim($directory, '/') . '/n3-pre-restore-*.sqlite') ?: [];
    usort($files, fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $file) unlink($file);
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) return;
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . '/' . $entry;
        is_dir($path) ? removeDirectory($path) : unlink($path);
    }
    rmdir($directory);
}
