<?php
declare(strict_types=1);
require __DIR__ . '/backup_lib.php';

try {
    $output = $argv[1] ?? ((getenv('DATA_DIR') ?: '/var/www/data') . '/backups');
    $retention = max(1, (int)(getenv('BACKUP_RETENTION') ?: 10));
    echo createBackup($output, null, $retention), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Backup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
