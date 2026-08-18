<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Database\MigrationRunner;
use N3\Service\AccountService;
use N3\Service\AuthService;

function verifyProfileMigration(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function expectProfileConstraint(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (PDOException) {
        echo "✓ $message\n";
        return;
    }
    throw new RuntimeException("Expected constraint failure: $message");
}

function removeProfileMigrationDirectory(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . '/' . $entry;
        if (is_dir($target) && !is_link($target)) removeProfileMigrationDirectory($target);
        else unlink($target);
    }
    rmdir($path);
}

function profileMigrationDatabase(string $path): PDO
{
    $database = new PDO('sqlite:' . $path);
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $database->exec('PRAGMA foreign_keys = ON');
    return $database;
}

$temp = sys_get_temp_dir() . '/n3-profile-migration-' . bin2hex(random_bytes(5));
mkdir($temp, 0700, true);
$migrationDirectory = dirname(__DIR__) . '/database/migrations';

try {
    $upgrade = profileMigrationDatabase($temp . '/upgrade.sqlite');
    $upgrade->exec(<<<'SQL'
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
            owner_id INTEGER REFERENCES users(id) ON DELETE SET NULL
        );
        CREATE TABLE pages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            space_id INTEGER NOT NULL,
            parent_id INTEGER,
            title TEXT NOT NULL,
            kind TEXT NOT NULL DEFAULT 'page',
            is_deleted INTEGER NOT NULL DEFAULT 0,
            is_public INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(space_id) REFERENCES spaces(id) ON DELETE CASCADE,
            FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL
        );
        CREATE TABLE resource_shares (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            resource_type TEXT NOT NULL,
            resource_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            role TEXT NOT NULL,
            granted_by INTEGER NOT NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY(granted_by) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE plugin_enablement_overrides (
            plugin_id TEXT PRIMARY KEY,
            enabled INTEGER NOT NULL,
            updated_by INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE
        );
        INSERT INTO users (id, username, password_hash, is_admin) VALUES
            (4, 'Owner Person', 'owner-hash', 1),
            (7, 'Élodie', 'reader-hash', 0);
        INSERT INTO spaces (id, name, owner_id) VALUES
            (10, 'Owner space', 4),
            (20, 'Member space', 7);
        INSERT INTO pages (id, space_id, title, is_public, updated_at) VALUES
            (100, 10, 'Private legacy page', 0, '2025-01-02 03:04:05'),
            (101, 10, 'Public legacy page', 1, '2025-02-03 04:05:06'),
            (200, 20, 'Deleted author page', 0, '2025-03-04 05:06:07');
        INSERT INTO resource_shares (resource_type, resource_id, user_id, role, granted_by)
            VALUES ('page', 100, 7, 'viewer', 4);
    SQL);

    $runner = new MigrationRunner($migrationDirectory, $temp, '0.4.0');
    $runner->run($upgrade, true);
    verifyProfileMigration(
        (int)$upgrade->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn() === 5
            && $upgrade->query('SELECT app_version FROM schema_migrations WHERE version = 3')->fetchColumn() === '0.4.0',
        'migration 003 remains recorded with its first shipping application version after later settings migrations',
    );
    $userColumns = array_column($upgrade->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    $pageColumns = array_column($upgrade->query('PRAGMA table_info(pages)')->fetchAll(), 'name');
    verifyProfileMigration(
        array_diff(['display_name', 'biography', 'profile_slug', 'profile_visibility', 'avatar_reference'], $userColumns) === []
            && array_diff(['author_id', 'last_editor_id', 'first_published_at'], $pageColumns) === [],
        'migration adds the complete profile and authorship schema',
    );
    $users = $upgrade->query('SELECT id, display_name, biography, profile_slug, profile_visibility, avatar_reference FROM users ORDER BY id')->fetchAll();
    verifyProfileMigration(
        $users === [
            ['id' => 4, 'display_name' => 'Owner Person', 'biography' => '', 'profile_slug' => 'owner-person-4', 'profile_visibility' => 'private', 'avatar_reference' => null],
            ['id' => 7, 'display_name' => 'Élodie', 'biography' => '', 'profile_slug' => 'elodie-7', 'profile_visibility' => 'private', 'avatar_reference' => null],
        ],
        'existing users receive deterministic private profiles without avatars',
    );
    $pages = $upgrade->query('SELECT id, author_id, last_editor_id, first_published_at FROM pages ORDER BY id')->fetchAll();
    verifyProfileMigration(
        $pages === [
            ['id' => 100, 'author_id' => 4, 'last_editor_id' => 4, 'first_published_at' => null],
            ['id' => 101, 'author_id' => 4, 'last_editor_id' => 4, 'first_published_at' => '2025-02-03 04:05:06'],
            ['id' => 200, 'author_id' => 7, 'last_editor_id' => 7, 'first_published_at' => null],
        ],
        'page authorship follows space ownership and public dates use the documented legacy estimate',
    );
    verifyProfileMigration(
        $upgrade->query("SELECT role FROM resource_shares WHERE resource_type = 'page' AND resource_id = 100 AND user_id = 7")->fetchColumn() === 'viewer',
        'profile backfill does not change existing access grants',
    );
    expectProfileConstraint(
        static fn() => $upgrade->exec("UPDATE users SET profile_visibility = 'everyone' WHERE id = 4"),
        'profile visibility accepts only the documented values',
    );
    expectProfileConstraint(
        static fn() => $upgrade->exec("UPDATE users SET profile_slug = 'OWNER-PERSON-4' WHERE id = 7"),
        'profile slugs remain unique case-insensitively',
    );
    $upgrade->exec('DELETE FROM users WHERE id = 7');
    $deletedAuthorPage = $upgrade->query('SELECT author_id, last_editor_id FROM pages WHERE id = 200')->fetch();
    verifyProfileMigration(
        $deletedAuthorPage === ['author_id' => null, 'last_editor_id' => null]
            && (int)$upgrade->query('SELECT COUNT(*) FROM pages WHERE id = 200')->fetchColumn() === 1,
        'deleting an author clears identity references without deleting page content',
    );
    verifyProfileMigration($upgrade->query('PRAGMA foreign_key_check')->fetchAll() === [], 'upgraded profile relationships pass foreign-key validation');
    $snapshotCount = count(glob($temp . '/n3-pre-migration-*.sqlite') ?: []);
    $runner->run($upgrade, true);
    verifyProfileMigration(
        count(glob($temp . '/n3-pre-migration-*.sqlite') ?: []) === $snapshotCount,
        'reopening the current schema is idempotent and creates no extra snapshot',
    );

    $clean = profileMigrationDatabase($temp . '/clean.sqlite');
    $clean->exec(<<<'SQL'
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
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY(space_id) REFERENCES spaces(id) ON DELETE CASCADE,
            FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL
        );
        INSERT INTO spaces (name) VALUES ('Seeded space');
        INSERT INTO pages (space_id, title) VALUES (1, 'Seeded page');
    SQL);
    (new MigrationRunner($migrationDirectory, $temp, '0.4.0'))->run($clean, true);
    $auth = new AuthService($clean);
    $ownerId = $auth->createOwner('First Owner', 'correct horse battery staple');
    verifyProfileMigration($ownerId === 1, 'first owner is created after clean pre-setup migration');
    $owner = $clean->query('SELECT display_name, profile_slug, profile_visibility FROM users WHERE id = 1')->fetch();
    $seeded = $clean->query('SELECT author_id, last_editor_id FROM pages WHERE id = 1')->fetch();
    verifyProfileMigration(
        $owner === ['display_name' => 'First Owner', 'profile_slug' => 'first-owner-1', 'profile_visibility' => 'private']
            && $seeded === ['author_id' => 1, 'last_editor_id' => 1]
            && (int)$clean->query('SELECT owner_id FROM spaces WHERE id = 1')->fetchColumn() === 1,
        'first-owner setup claims seeded spaces and unassigned page authorship atomically',
    );
    $collaboratorId = $auth->createCollaborator('Local Writer', 'another correct horse battery staple');
    $collaborator = $clean->query("SELECT display_name, profile_slug, profile_visibility FROM users WHERE id = $collaboratorId")->fetch();
    verifyProfileMigration(
        $collaborator === ['display_name' => 'Local Writer', 'profile_slug' => 'local-writer-2', 'profile_visibility' => 'private'],
        'new collaborator accounts receive stable private profiles',
    );
    (new AccountService($clean))->changeCredentials(1, 1, 'correct horse battery staple', 'Renamed Owner', '');
    verifyProfileMigration(
        $clean->query('SELECT profile_slug FROM users WHERE id = 1')->fetchColumn() === 'first-owner-1',
        'credential username changes do not change the stable profile slug',
    );
    verifyProfileMigration($clean->query('PRAGMA foreign_key_check')->fetchAll() === [], 'clean profile schema passes foreign-key validation');

    echo "\nn3 profile migration test passed.\n";
} finally {
    unset($upgrade, $clean);
    removeProfileMigrationDirectory($temp);
}
