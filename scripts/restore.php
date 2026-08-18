<?php
declare(strict_types=1);
require __DIR__ . '/backup_lib.php';

try {
    if (empty($argv[1])) throw new InvalidArgumentException('Usage: php scripts/restore.php BACKUP.tar.gz');
    $manifest = restoreBackup($argv[1]);
    echo 'Restored backup from ', $manifest['created_at'], PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Restore failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
