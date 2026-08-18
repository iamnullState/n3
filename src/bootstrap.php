<?php
declare(strict_types=1);

require_once __DIR__ . '/autoload.php';

use N3\Config;
use N3\Database\MigrationRunner;
use N3\Plugin\PluginManager;
use N3\Plugin\PluginRegistry;
use N3\Repository\AppSettingsRepository;
use N3\Service\AppSettingsService;
use N3\Support\Version;

date_default_timezone_set(Config::timezone());
define('DATA_DIR', Config::dataDir());
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

set_exception_handler(static function (Throwable $error): never {
    error_log(json_encode([
        'timestamp' => gmdate(DATE_ATOM),
        'level' => 'error',
        'event' => 'unhandled_exception',
        'request_id' => requestId(),
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'path' => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        'error_class' => $error::class,
        'error_code' => (string)$error->getCode(),
        'source' => basename($error->getFile()) . ':' . $error->getLine(),
    ], JSON_UNESCAPED_SLASHES));
    if (!headers_sent()) {
        http_response_code(500);
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        if (str_starts_with($path, '/api/')) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'The application could not complete this request.']);
            exit(1);
        }
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'The application could not complete this request.';
    exit(1);
});

function requestId(): string
{
    static $id;
    if (is_string($id)) return $id;
    $provided = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $id = preg_match('/^[A-Za-z0-9._-]{8,80}$/D', $provided) ? $provided : bin2hex(random_bytes(12));
    return $id;
}

function requestIsHttps(): bool
{
    if (Config::publicHttps()) return true;
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!Config::isTrustedProxy($remote)) return false;
    $forwarded = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded === 'https';
}

function requestClientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!Config::isTrustedProxy($remote)) return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
    foreach (explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')) as $candidate) {
        $candidate = trim($candidate);
        if (filter_var($candidate, FILTER_VALIDATE_IP)) return $candidate;
    }
    return filter_var($remote, FILTER_VALIDATE_IP) ? $remote : 'unknown';
}

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) return $pdo;

    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $databasePath = DATA_DIR . '/n3.sqlite';
    $databaseExisted = is_file($databasePath) && filesize($databasePath) > 0;
    $pdo = new PDO('sqlite:' . $databasePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
    migrate($pdo, $databaseExisted);
    $runtimeSettings = (new AppSettingsService(new AppSettingsRepository($pdo), DATA_DIR))->all();
    Config::setRuntimeSettings($runtimeSettings);
    return $pdo;
}

function migrate(PDO $pdo, bool $databaseExisted = false): void
{
    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS spaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            icon TEXT NOT NULL DEFAULT 'book',
            color TEXT NOT NULL DEFAULT '#415a77',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            space_id INTEGER NOT NULL,
            parent_id INTEGER,
            title TEXT NOT NULL DEFAULT 'Untitled',
            content TEXT NOT NULL DEFAULT '<p></p>',
            position INTEGER NOT NULL DEFAULT 0,
            is_favorite INTEGER NOT NULL DEFAULT 0,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(space_id) REFERENCES spaces(id) ON DELETE CASCADE,
            FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL
        );
        CREATE INDEX IF NOT EXISTS idx_pages_space ON pages(space_id, is_deleted, position);
        CREATE INDEX IF NOT EXISTS idx_pages_parent ON pages(parent_id, position);
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS auth_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_address TEXT NOT NULL,
            attempted_at INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_auth_attempts_ip_time ON auth_attempts(ip_address, attempted_at);
        CREATE TABLE IF NOT EXISTS tags (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE COLLATE NOCASE,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE IF NOT EXISTS page_tags (
            page_id INTEGER NOT NULL,
            tag_id INTEGER NOT NULL,
            PRIMARY KEY(page_id, tag_id),
            FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE,
            FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
        );
    SQL);

    $columns = $pdo->query('PRAGMA table_info(pages)')->fetchAll();
    if (!in_array('is_public', array_column($columns, 'name'), true)) {
        $pdo->exec('ALTER TABLE pages ADD COLUMN is_public INTEGER NOT NULL DEFAULT 0');
    }
    if (!in_array('kind', array_column($columns, 'name'), true)) {
        $pdo->exec("ALTER TABLE pages ADD COLUMN kind TEXT NOT NULL DEFAULT 'page' CHECK(kind IN ('page', 'folder'))");
    }
    if (!in_array('slug', array_column($columns, 'name'), true)) {
        $pdo->exec('ALTER TABLE pages ADD COLUMN slug TEXT');
        $rows = $pdo->query("SELECT id, title FROM pages WHERE kind = 'page'")->fetchAll();
        $update = $pdo->prepare('UPDATE pages SET slug = ? WHERE id = ?');
        foreach ($rows as $row) $update->execute([slugify($row['title']) . '-' . (int)$row['id'], (int)$row['id']]);
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug) WHERE slug IS NOT NULL');
    }
    if (!in_array('content_revision', array_column($columns, 'name'), true)) {
        $pdo->exec('ALTER TABLE pages ADD COLUMN content_revision INTEGER NOT NULL DEFAULT 1');
    }
    if (!in_array('feature_image', array_column($columns, 'name'), true)) {
        $pdo->exec('ALTER TABLE pages ADD COLUMN feature_image TEXT');
    }
    if (!in_array('feature_image_opacity', array_column($columns, 'name'), true)) {
        $pdo->exec('ALTER TABLE pages ADD COLUMN feature_image_opacity INTEGER NOT NULL DEFAULT 50');
    }
    $userColumns = $pdo->query('PRAGMA table_info(users)')->fetchAll();
    if (!in_array('session_version', array_column($userColumns, 'name'), true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1');
    }

    $pdo->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS page_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            revision INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'edit',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(page_id, revision),
            FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE
        );
        CREATE INDEX IF NOT EXISTS idx_page_revisions_page ON page_revisions(page_id, revision DESC);
    SQL);

    $count = (int)$pdo->query('SELECT COUNT(*) FROM spaces')->fetchColumn();
    if ($count === 0) seed($pdo);
    $missingSlugs = $pdo->query("SELECT id, title FROM pages WHERE kind = 'page' AND (slug IS NULL OR slug = '')")->fetchAll();
    $updateSlug = $pdo->prepare('UPDATE pages SET slug = ? WHERE id = ?');
    foreach ($missingSlugs as $row) $updateSlug->execute([slugify($row['title']) . '-' . (int)$row['id'], (int)$row['id']]);
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_pages_slug ON pages(slug) WHERE slug IS NOT NULL');
    $pdo->exec("INSERT INTO page_revisions (page_id, revision, title, content, source) SELECT id, content_revision, title, content, 'initial' FROM pages WHERE kind = 'page' AND NOT EXISTS (SELECT 1 FROM page_revisions WHERE page_revisions.page_id = pages.id)");
    (new MigrationRunner(Config::projectRoot() . '/database/migrations', DATA_DIR, Version::current()))->run($pdo, $databaseExisted);
}

function seed(PDO $pdo): void
{
    $pdo->beginTransaction();
    $space = $pdo->prepare('INSERT INTO spaces (name, description, icon, color) VALUES (?, ?, ?, ?)');
    $space->execute(['Personal Wiki', 'Ideas, notes, and everything worth remembering.', 'sparkles', '#415a77']);
    $spaceId = (int)$pdo->lastInsertId();

    $page = $pdo->prepare('INSERT INTO pages (space_id, parent_id, title, content, position, is_favorite) VALUES (?, ?, ?, ?, ?, ?)');
    $welcome = <<<'HTML'
<p class="lead">Your calm, private corner of the internet—made for notes that deserve to become knowledge.</p>
<div class="callout callout-purple"><span class="callout-icon">✦</span><div><strong>Welcome to n3</strong><p>Everything is already running locally. Create a page, start typing, and your work saves automatically.</p></div></div>
<h2>Make this space yours</h2>
<p>Combine the structure of a wiki with the ease of a blog. Use nested pages for deep topics, favorites for daily notes, and search when you cannot remember where something lives.</p>
<div class="feature-grid"><div class="feature-card"><span>⌘</span><strong>Write naturally</strong><p>Use the rich toolbar, keyboard shortcuts, lists, callouts, links, and code blocks.</p></div><div class="feature-card"><span>◐</span><strong>Easy on the eyes</strong><p>Choose light, dark, or your system theme from the sidebar.</p></div><div class="feature-card"><span>⌕</span><strong>Find anything</strong><p>Press <code>Ctrl K</code> or <code>⌘ K</code> to search every page instantly.</p></div><div class="feature-card"><span>↗</span><strong>Own your words</strong><p>Export any page as HTML or Markdown. Your data stays in a single SQLite file.</p></div></div>
<h2>A tiny workflow that works</h2>
<ol><li>Create a page from the sidebar.</li><li>Give it a clear title and write without worrying about saving.</li><li>Nest supporting pages beneath it as the idea grows.</li></ol>
<blockquote>Good notes are not a warehouse. They are a garden you can wander through.</blockquote>
HTML;
    $page->execute([$spaceId, null, 'Welcome to your wiki', $welcome, 0, 1]);
    $welcomeId = (int)$pdo->lastInsertId();
    $page->execute([$spaceId, $welcomeId, 'Writing guide', '<h2>Start with a thought</h2><p>Select text to format it, or use the toolbar above the page. Your edits are saved a moment after you pause.</p><h3>Useful shortcuts</h3><ul><li><strong>Ctrl/⌘ B</strong> for bold</li><li><strong>Ctrl/⌘ I</strong> for italic</li><li><strong>Ctrl/⌘ K</strong> to search</li></ul><pre><code>// Code blocks are welcome here, too.\nconst idea = "worth keeping";</code></pre>', 0, 0]);
    $page->execute([$spaceId, null, 'Ideas & inspiration', '<p class="lead">A home for half-formed thoughts, sparks, and things to revisit.</p><h2>Inbox</h2><ul><li>Write the smallest useful version first</li><li>Connect ideas with links and nested pages</li><li>Review favorites every Friday</li></ul>', 1, 0]);
    $ideasId = (int)$pdo->lastInsertId();
    $page->execute([$spaceId, $ideasId, 'Someday projects', '<h2>Someday projects</h2><p>A place for ambitions without deadlines.</p><ul><li>Build a tiny reading room</li><li>Publish a field guide</li><li>Learn a new instrument</li></ul>', 0, 0]);
    $page->execute([$spaceId, null, 'Reading notes', '<p class="lead">Books, essays, and the lines that stayed with me.</p><div class="callout callout-blue"><span class="callout-icon">☁</span><div><strong>Reading ritual</strong><p>Capture one idea, one quote in your own words, and one question for later.</p></div></div>', 2, 0]);
    $pdo->commit();
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('n3_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => requestIsHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function pluginRegistry(): PluginRegistry
{
    return $GLOBALS['n3_plugin_registry'];
}

function pluginManager(): PluginManager
{
    return $GLOBALS['n3_plugin_manager'];
}

$GLOBALS['n3_plugin_registry'] = new PluginRegistry();
$GLOBALS['n3_plugin_manager'] = new PluginManager(Config::pluginDir(), $GLOBALS['n3_plugin_registry']);
$GLOBALS['n3_plugin_manager']->discover();
