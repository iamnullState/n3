<?php
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/backup_lib.php';

function verifyUpgrade(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

if (empty($argv[1]) || !is_file($argv[1])) {
    fwrite(STDERR, "Usage: php tests/upgrade_compatibility.php BACKUP.tar.gz\n");
    exit(2);
}

$archive = realpath($argv[1]);
$work = sys_get_temp_dir() . '/n3-upgrade-test-' . bin2hex(random_bytes(5));

try {
    $manifest = inspectArchive($archive);
    verifyUpgrade(($manifest['version'] ?? 0) === 1, 'source backup format is supported');

    restoreBackup($archive, $work);
    $database = backupDatabasePath($work);
    $sourceDatabase = openBackupDatabase($database);
    $sourceSchemaVersion = databaseSchemaVersion($sourceDatabase);
    unset($sourceDatabase);
    $before = validateDatabase($database, $manifest);
    verifyUpgrade($before === ($manifest['counts'] ?? null), 'restored record counts match the source manifest');

    $sessionPath = $work . '/sessions';
    if (!mkdir($sessionPath, 0700, true) && !is_dir($sessionPath)) throw new RuntimeException('Could not create the isolated upgrade session directory.');
    $command = [PHP_BINARY, '-d', 'session.save_path=' . $sessionPath, dirname(__DIR__) . '/public/index.php'];
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, dirname(__DIR__), [
        'APP_NAME' => 'n3 upgrade verification',
        'APP_TIMEZONE' => 'UTC',
        'APP_URL' => 'http://127.0.0.1',
        'DATA_DIR' => $work,
        'REQUEST_METHOD' => 'GET',
        'REQUEST_URI' => '/public',
    ]);
    if (!is_resource($process)) throw new RuntimeException('Could not start the current application against the restored database.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    verifyUpgrade($exit === 0 && str_contains($output, '<!doctype html>'), 'current application boots against the restored database');
    verifyUpgrade(trim($errors) === '', 'upgrade boot emits no runtime errors');

    $after = validateDatabase($database);
    verifyUpgrade($after === $before, 'application startup preserves all record counts');

    $pdo = openBackupDatabase($database);
    $foreignKeyErrors = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
    verifyUpgrade($foreignKeyErrors === [], 'upgraded database has no foreign-key violations');
    verifyUpgrade((int)$pdo->query("SELECT COUNT(*) FROM users WHERE password_hash != ''")->fetchColumn() === $after['users'], 'owner authentication hashes remain present');
    verifyUpgrade((int)$pdo->query('SELECT COUNT(*) FROM pages WHERE parent_id IS NOT NULL')->fetchColumn() > 0, 'page hierarchy remains present');
    verifyUpgrade((int)$pdo->query('SELECT COUNT(*) FROM page_tags')->fetchColumn() > 0, 'page and tag relationships remain present');
    verifyUpgrade((int)$pdo->query('SELECT COUNT(*) FROM page_revisions')->fetchColumn() === $after['revisions'], 'complete revision history remains present');
    $userColumns = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    $pageColumns = array_column($pdo->query('PRAGMA table_info(pages)')->fetchAll(), 'name');
    verifyUpgrade(
        array_diff(['display_name', 'biography', 'profile_slug', 'profile_visibility', 'avatar_reference'], $userColumns) === []
            && array_diff(['author_id', 'last_editor_id', 'first_published_at'], $pageColumns) === [],
        'upgrade installs the complete profile and authorship schema',
    );
    verifyUpgrade(
        (int)$pdo->query("SELECT COUNT(*) FROM users WHERE profile_slug IS NULL OR profile_slug = '' OR profile_visibility NOT IN ('private', 'members', 'public')")->fetchColumn() === 0
            && (int)$pdo->query('SELECT COUNT(DISTINCT lower(profile_slug)) FROM users')->fetchColumn() === $after['users'],
        'upgraded users have non-empty unique stable profile slugs and valid visibility',
    );
    verifyUpgrade(
        (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE is_public = 1 AND first_published_at IS NULL")->fetchColumn() === 0,
        'upgraded published pages retain a first-publication date',
    );
    if ($sourceSchemaVersion < 3) {
        verifyUpgrade(
            (int)$pdo->query('SELECT COUNT(*) FROM pages JOIN spaces ON spaces.id = pages.space_id WHERE spaces.owner_id IS NOT NULL AND (pages.author_id != spaces.owner_id OR pages.last_editor_id != spaces.owner_id)')->fetchColumn() === 0,
            'pre-profile pages backfill author and last-editor identity from space ownership',
        );
        verifyUpgrade(
            (int)$pdo->query("SELECT COUNT(*) FROM pages WHERE is_public = 1 AND first_published_at != updated_at")->fetchColumn() === 0,
            'legacy public pages use their preserved update date as the publication estimate',
        );
    }

    echo "\nn3 upgrade compatibility test passed.\n";
} finally {
    removeDirectory($work);
}
