<?php
declare(strict_types=1);

require dirname(__DIR__) . '/scripts/backup_lib.php';

function verifyProfileUpgrade(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$work = sys_get_temp_dir() . '/n3-profile-upgrade-' . bin2hex(random_bytes(5));
$data = $work . '/data';
$archives = $work . '/archives';
mkdir($data, 0700, true);
mkdir($archives, 0700, true);

try {
    $database = openBackupDatabase(backupDatabasePath($data));
    $database->exec(<<<'SQL'
        PRAGMA foreign_keys = ON;
        CREATE TABLE schema_migrations (
            version INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            app_version TEXT NOT NULL,
            applied_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        INSERT INTO schema_migrations (version, name, app_version) VALUES
            (0, 'pre-ledger baseline', '0.1.0'),
            (1, 'add extensions references and collaboration', '0.3.0'),
            (2, 'add plugin enablement overrides', '0.3.0');
        CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            session_version INTEGER NOT NULL DEFAULT 1,
            is_admin INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE spaces (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT NOT NULL DEFAULT '',
            icon TEXT NOT NULL DEFAULT 'book',
            color TEXT NOT NULL DEFAULT '#6d5dfc',
            owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        );
        CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            space_id INTEGER NOT NULL,
            parent_id INTEGER,
            title TEXT NOT NULL DEFAULT 'Untitled',
            slug TEXT,
            kind TEXT NOT NULL DEFAULT 'page',
            content TEXT NOT NULL DEFAULT '<p></p>',
            position INTEGER NOT NULL DEFAULT 0,
            is_favorite INTEGER NOT NULL DEFAULT 0,
            is_public INTEGER NOT NULL DEFAULT 0,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            content_revision INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(space_id) REFERENCES spaces(id) ON DELETE CASCADE,
            FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL
        );
        CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE COLLATE NOCASE, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
        CREATE TABLE page_tags (page_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY(page_id, tag_id));
        CREATE TABLE page_revisions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            page_id INTEGER NOT NULL,
            revision INTEGER NOT NULL,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT 'initial',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(page_id, revision)
        );
        CREATE TABLE page_references (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, label TEXT NOT NULL, url TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE resource_shares (id INTEGER PRIMARY KEY AUTOINCREMENT, resource_type TEXT NOT NULL, resource_id INTEGER NOT NULL, user_id INTEGER NOT NULL, role TEXT NOT NULL, granted_by INTEGER NOT NULL);
        CREATE TABLE plugin_enablement_overrides (plugin_id TEXT PRIMARY KEY, enabled INTEGER NOT NULL, updated_by INTEGER NOT NULL);

        INSERT INTO users (id, username, password_hash, is_admin) VALUES (1, 'Legacy Owner', 'legacy-password-hash', 1);
        INSERT INTO spaces (id, name, owner_id) VALUES (1, 'Legacy space', 1);
        INSERT INTO pages (id, space_id, title, slug, kind, content, is_public, updated_at) VALUES
            (10, 1, 'Legacy folder', NULL, 'folder', '<p></p>', 0, '2025-01-01 01:02:03'),
            (11, 1, 'Legacy public page', 'legacy-public-11', 'page', '<p>Public legacy content</p>', 1, '2025-02-03 04:05:06');
        UPDATE pages SET parent_id = 10 WHERE id = 11;
        INSERT INTO tags (id, name) VALUES (1, 'legacy');
        INSERT INTO page_tags (page_id, tag_id) VALUES (11, 1);
        INSERT INTO page_revisions (page_id, revision, title, content) VALUES (11, 1, 'Legacy public page', '<p>Public legacy content</p>');
    SQL);
    unset($database);

    $archive = createBackup($archives, $data, 2);
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/upgrade_compatibility.php', $archive],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) throw new RuntimeException('Could not start the profile upgrade verifier.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($exit !== 0) {
        throw new RuntimeException("Profile upgrade verifier failed:\n" . $output . "\n" . $errors);
    }
    verifyProfileUpgrade($exit === 0, 'schema-2 backup upgrades successfully through the reusable compatibility verifier');
    verifyProfileUpgrade(trim((string)$errors) === '', 'profile upgrade compatibility emits no runtime errors');
    verifyProfileUpgrade(
        str_contains((string)$output, 'upgrade installs the complete profile and authorship schema')
            && str_contains((string)$output, 'pre-profile pages backfill author and last-editor identity from space ownership')
            && str_contains((string)$output, 'legacy public pages use their preserved update date as the publication estimate'),
        'upgrade verification proves profile, authorship, and publication-date backfills',
    );

    echo "\nn3 profile upgrade compatibility test passed.\n";
} finally {
    removeDirectory($work);
}
