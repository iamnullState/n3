<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Database\MigrationRunner;
use N3\Plugin\PluginManager;
use N3\Plugin\PluginRegistry;
use N3\Repository\PageReferenceRepository;
use N3\Repository\PluginEnablementRepository;
use N3\Service\AccessService;
use N3\Service\PluginEnablementService;

function verifyExtension(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$temp = sys_get_temp_dir() . '/n3-extensions-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$releaseVersion = trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'));
$databasePath = $temp . '/n3.sqlite';
$database = new PDO('sqlite:' . $databasePath);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT NOT NULL UNIQUE COLLATE NOCASE,
        password_hash TEXT NOT NULL,
        session_version INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE spaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        description TEXT NOT NULL DEFAULT '',
        icon TEXT NOT NULL DEFAULT 'book',
        color TEXT NOT NULL DEFAULT '#000000',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        space_id INTEGER NOT NULL,
        parent_id INTEGER,
        title TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'page',
        is_deleted INTEGER NOT NULL DEFAULT 0,
        is_public INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(space_id) REFERENCES spaces(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL
    );
    INSERT INTO users (username, password_hash) VALUES ('owner', 'hash'), ('reader', 'hash');
    INSERT INTO spaces (name) VALUES ('Research');
    INSERT INTO pages (space_id, title) VALUES (1, 'Timeline');
    INSERT INTO pages (space_id, parent_id, title) VALUES (1, 1, 'Timeline child');
SQL);

$runner = new MigrationRunner(dirname(__DIR__) . '/database/migrations', $temp, $releaseVersion);
$runner->run($database, true);
verifyExtension((int)$database->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 5, 'numbered migration ledger records extensions, plugin administration, profiles, application settings, and plugin migrations');
verifyExtension(
    (bool)$database->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'plugin_migrations'")->fetchColumn(),
    'core schema provides the plugin migration ledger without installing plugin-owned tables',
);
verifyExtension(
    $database->query('SELECT app_version FROM schema_migrations WHERE version > 0 ORDER BY version')->fetchAll(PDO::FETCH_COLUMN) === ['0.3.0', '0.3.0', '0.4.0', '0.4.0', '0.5.0'],
    'numbered migrations record their first shipping application version',
);
verifyExtension(count(glob($temp . '/n3-pre-migration-*.sqlite') ?: []) === 1, 'an existing database is snapshotted before migration');
$runner->run($database, true);
verifyExtension(count(glob($temp . '/n3-pre-migration-*.sqlite') ?: []) === 1, 'reopening an up-to-date database does not migrate or snapshot again');
verifyExtension((int)$database->query('SELECT owner_id FROM spaces WHERE id = 1')->fetchColumn() === 1, 'migration assigns existing spaces to the original owner');

$references = new PageReferenceRepository($database);
$references->replaceForPage(1, [
    ['label' => 'External source', 'url' => 'https://example.com/source'],
    ['label' => 'Related note', 'url' => '/page/2'],
]);
verifyExtension(array_column($references->forPage(1), 'label') === ['External source', 'Related note'], 'page references preserve display order and internal links');

$owner = new AccessService($database, 1);
$reader = new AccessService($database, 2);
verifyExtension($owner->canManageSpace(1) && !$reader->canViewPage(1), 'space ownership starts private to its owner');
$owner->grant('page', 1, 2, 'viewer');
verifyExtension($reader->canViewPage(1) && $reader->canViewPage(2) && !$reader->canEditPage(2), 'viewer access inherits through a shared page subtree');
$owner->grant('page', 1, 2, 'editor');
verifyExtension($reader->canEditPage(2), 'editor access inherits through a shared page subtree');

$pluginEnablement = new PluginEnablementService(new PluginEnablementRepository($database));
verifyExtension($pluginEnablement->overrides() === [], 'plugin enablement defaults to manifests when no database override exists');
$pluginEnablement->set('example', false, 1);
verifyExtension($pluginEnablement->overrides() === ['example' => false], 'plugin enablement overrides persist independently of the plugin directory');

$pluginDirectory = $temp . '/plugins/example';
mkdir($pluginDirectory, 0700, true);
file_put_contents($pluginDirectory . '/plugin.css', '.example{}');
file_put_contents($pluginDirectory . '/plugin.js', 'window.examplePlugin = true;');
file_put_contents($pluginDirectory . '/plugin.json', json_encode([
    'name' => 'Example',
    'enabled' => true,
    'css' => ['plugin.css'],
    'js' => ['plugin.js'],
    'dashboard' => [['title' => 'Example card', 'body' => 'Ready']],
]));
$disabledManager = new PluginManager($temp . '/plugins', new PluginRegistry());
$disabledManager->applyEnablementOverrides($pluginEnablement->overrides());
verifyExtension(
    $disabledManager->boot()->plugins() === []
        && ($disabledManager->inventory()[0]['manifest_enabled'] ?? null) === true
        && ($disabledManager->inventory()[0]['override_enabled'] ?? null) === false
        && ($disabledManager->inventory()[0]['effective_enabled'] ?? null) === false,
    'a persisted disable override supersedes an enabled manifest without modifying plugin files',
);
$pluginEnablement->set('example', true, 1);
$enabledManager = new PluginManager($temp . '/plugins', new PluginRegistry());
$enabledManager->applyEnablementOverrides($pluginEnablement->overrides());
$registry = $enabledManager->boot();
$plugin = $registry->plugins()[0] ?? null;
verifyExtension(
    $plugin !== null
        && $plugin['dashboard'][0]['title'] === 'Example card'
        && count($plugin['css']) === 1
        && count($plugin['js']) === 1
        && ($enabledManager->inventory()[0]['effective_enabled'] ?? null) === true,
    'a persisted enable override boots the plugin and preserves its registered contributions',
);

$failureDirectory = $temp . '/failure-migrations';
mkdir($failureDirectory, 0700, true);
file_put_contents($failureDirectory . '/001_fail_safely.php', <<<'PHP'
<?php
return [
    'name' => 'fail safely',
    'up' => static function (\PDO $database): void {
        $database->exec('CREATE TABLE partial_change (id INTEGER PRIMARY KEY)');
        throw new \RuntimeException('expected migration failure');
    },
];
PHP);
$failurePath = $temp . '/failure.sqlite';
$failureDatabase = new PDO('sqlite:' . $failurePath);
$failureDatabase->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$failed = false;
try {
    (new MigrationRunner($failureDirectory, $temp, $releaseVersion))->run($failureDatabase, true);
} catch (RuntimeException $error) {
    $failed = $error->getMessage() === 'expected migration failure';
}
$partial = (bool)$failureDatabase->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'partial_change'")->fetchColumn();
$recorded = (int)$failureDatabase->query('SELECT COUNT(*) FROM schema_migrations WHERE version = 1')->fetchColumn();
verifyExtension($failed && !$partial && $recorded === 0, 'failed migrations roll back schema changes and ledger records together');
$failureDatabase->exec("INSERT INTO schema_migrations (version, name, app_version) VALUES (99, 'future', '99.0.0')");
$futureRejected = false;
try {
    (new MigrationRunner($failureDirectory, $temp, $releaseVersion))->run($failureDatabase, true);
} catch (RuntimeException $error) {
    $futureRejected = str_contains($error->getMessage(), 'newer version');
}
verifyExtension($futureRejected, 'an older application rejects a database with a newer schema ledger');

echo "\nn3 extension and collaboration test passed.\n";
