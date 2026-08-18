<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Service\SystemDiagnosticsService;

function verifyDiagnostics(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function removeDiagnosticsDirectory(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . '/' . $entry;
        if (is_dir($target) && !is_link($target)) removeDiagnosticsDirectory($target);
        else unlink($target);
    }
    rmdir($path);
}

$temp = sys_get_temp_dir() . '/n3-diagnostics-' . bin2hex(random_bytes(5));
$backups = $temp . '/backups';
mkdir($backups, 0700, true);
$databasePath = $temp . '/n3.sqlite';
$database = new PDO('sqlite:' . $databasePath);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    PRAGMA foreign_keys = ON;
    CREATE TABLE schema_migrations (
        version INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        app_version TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO schema_migrations (version, name, app_version) VALUES (0, 'baseline', '0.1.0'), (1, 'first', '0.3.0'), (2, 'second', '0.3.0');
SQL);
$archive = $backups . '/n3-20260725-120000-test.tar.gz';
file_put_contents($archive, 'diagnostic archive');
touch($archive, time() - 90);

try {
    $report = (new SystemDiagnosticsService($database, $temp, $backups, '0.3.0'))->report();
    verifyDiagnostics(
        $report['version'] === '0.3.0' && is_string($report['checked_at']),
        'report identifies the application version and check time',
    );
    verifyDiagnostics(
        $report['storage']['status'] === 'ok'
            && $report['storage']['data_writable'] === true
            && $report['storage']['database_writable'] === true
            && $report['storage']['database_bytes'] > 0
            && $report['storage']['free_bytes'] > 0,
        'storage diagnostics verify real writability and capacity',
    );
    verifyDiagnostics(
        $report['database'] === ['status' => 'ok', 'integrity' => 'ok', 'foreign_keys' => 'ok', 'schema_version' => 2],
        'database diagnostics verify integrity, foreign keys, and schema version',
    );
    verifyDiagnostics(
        $report['backup']['status'] === 'available'
            && $report['backup']['size_bytes'] === strlen('diagnostic archive')
            && $report['backup']['age_seconds'] >= 90
            && is_string($report['backup']['latest_at']),
        'backup diagnostics report the newest archive without exposing its path',
    );
    verifyDiagnostics(
        !str_contains(json_encode($report, JSON_THROW_ON_ERROR), $temp),
        'diagnostic output omits filesystem paths',
    );

    $missing = (new SystemDiagnosticsService($database, $temp, $temp . '/not-configured', '0.3.0'))->report();
    verifyDiagnostics(
        $missing['backup'] === ['status' => 'missing', 'latest_at' => null, 'age_seconds' => null, 'size_bytes' => null],
        'a missing backup directory produces a stable non-sensitive status',
    );

    echo "\nn3 system diagnostics test passed.\n";
} finally {
    unset($database);
    removeDiagnosticsDirectory($temp);
}
