<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Config;
use N3\Support\Version;

function verifyAutoload(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

verifyAutoload(class_exists(Config::class), 'autoload resolves a root N3 class');
verifyAutoload(class_exists(Version::class), 'autoload resolves a nested N3 class');
verifyAutoload(Config::projectRoot() === dirname(__DIR__), 'configuration resolves the project root');
verifyAutoload(Config::pluginDir() === rtrim(getenv('N3_PLUGIN_DIR') ?: dirname(__DIR__) . '/plugins', '/'), 'configuration resolves the plugin directory');
verifyAutoload(Config::backupDir() === rtrim(getenv('BACKUP_DIR') ?: Config::dataDir() . '/backups', '/'), 'configuration resolves the backup directory');
verifyAutoload(Config::timezone() === (getenv('APP_TIMEZONE') ?: 'UTC'), 'configuration reads environment defaults');
verifyAutoload(Version::current() === trim((string)file_get_contents(dirname(__DIR__) . '/VERSION')), 'version service reads the semantic application version');
verifyAutoload(!class_exists('N3\\Missing\\Example'), 'unknown N3 classes fail without loading unrelated files');

echo "\nn3 autoload smoke test passed.\n";
