<?php
declare(strict_types=1);
require __DIR__ . '/../scripts/backup_lib.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$dataDir = getenv('DATA_DIR') ?: '/var/www/data';
$sourcePath = backupDatabasePath($dataDir);
$work = sys_get_temp_dir() . '/n3-backup-test-' . bin2hex(random_bytes(5));
$archives = $work . '/archives';
$fresh = $work . '/fresh-data';
mkdir($archives, 0700, true);

try {
    $pdo = openBackupDatabase($sourcePath);
    $pdo->beginTransaction();
    $pdo->exec('CREATE TABLE IF NOT EXISTS backup_probe_records (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    $pdo->exec("INSERT OR REPLACE INTO backup_probe_records (id, value) VALUES (1, 'plugin-owned backup canary')");
    $pdo->prepare('INSERT OR REPLACE INTO plugin_migrations (plugin_id, migration, name, checksum) VALUES (?, ?, ?, ?)')
        ->execute(['backup-probe', 1, 'backup contract probe', str_repeat('a', 64)]);
    $ownerId = (int)$pdo->query('SELECT MIN(id) FROM users')->fetchColumn();
    $pdo->prepare('INSERT INTO spaces (name, description, color, owner_id) VALUES (?, ?, ?, ?)')->execute(['Round-trip space', 'Backup fixture', '#345678', $ownerId]);
    $spaceId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pages (space_id, title, kind, position, author_id, last_editor_id) VALUES (?, 'Archive folder', 'folder', 0, ?, ?)")->execute([$spaceId, $ownerId, $ownerId]);
    $folderId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pages (space_id, parent_id, title, slug, kind, content, position, is_public, content_revision, author_id, last_editor_id) VALUES (?, ?, 'Private archive page', 'private-archive-page-test', 'page', '<p>private backup canary</p>', 0, 0, 2, ?, ?)")->execute([$spaceId, $folderId, $ownerId, $ownerId]);
    $privateId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO pages (space_id, parent_id, title, slug, kind, content, position, is_public, content_revision, author_id, last_editor_id, first_published_at) VALUES (?, ?, 'Public archive page', 'public-archive-page-test', 'page', '<p>public backup canary</p>', 1, 1, 1, ?, ?, '2026-07-25 12:00:00')")->execute([$spaceId, $folderId, $ownerId, $ownerId]);
    $publicId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO page_revisions (page_id, revision, title, content, source) VALUES (?, 1, 'Private archive page', '<p>private earlier version</p>', 'initial'), (?, 2, 'Private archive page', '<p>private backup canary</p>', 'edit'), (?, 1, 'Public archive page', '<p>public backup canary</p>', 'initial')")->execute([$privateId, $privateId, $publicId]);
    $pdo->prepare("INSERT INTO tags (name) VALUES ('round-trip-tag')")->execute();
    $tagId = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO page_tags (page_id, tag_id) VALUES (?, ?), (?, ?)')->execute([$privateId, $tagId, $publicId, $tagId]);
    $pdo->prepare('INSERT INTO page_references (page_id, label, url, position) VALUES (?, ?, ?, 0)')->execute([$privateId, 'Backup reference', 'https://example.com/backup-reference']);
    $pdo->prepare("INSERT INTO users (username, password_hash) VALUES ('backup-reader', 'fixture-hash') ON CONFLICT(username) DO NOTHING")->execute();
    $readerId = (int)$pdo->query("SELECT id FROM users WHERE username = 'backup-reader'")->fetchColumn();
    $avatarName = str_repeat('c', 40) . '.webp';
    $pdo->prepare("UPDATE users SET username = 'backup-renamed-reader', display_name = 'Backup Reader', profile_slug = ?, profile_visibility = 'members', avatar_reference = ? WHERE id = ?")->execute(['backup-reader-' . $readerId, $avatarName, $readerId]);
    $pdo->prepare("INSERT INTO resource_shares (resource_type, resource_id, user_id, role, granted_by) VALUES ('page', ?, ?, 'viewer', ?) ON CONFLICT(resource_type, resource_id, user_id) DO UPDATE SET role = 'viewer'")->execute([$privateId, $readerId, $ownerId]);
    $pdo->commit();
    unset($pdo);
    $mediaName = str_repeat('b', 40) . '.jpg';
    if (!is_dir($dataDir . '/media')) mkdir($dataDir . '/media', 0770, true);
    file_put_contents($dataDir . '/media/' . $mediaName, 'backup media canary');
    if (!is_dir($dataDir . '/avatars')) mkdir($dataDir . '/avatars', 0770, true);
    file_put_contents($dataDir . '/avatars/' . $avatarName, 'backup avatar canary');
    $orphanAvatarName = str_repeat('d', 40) . '.png';
    file_put_contents($dataDir . '/avatars/' . $orphanAvatarName, 'unreferenced avatar must not be archived');
    $pluginMediaName = str_repeat('e', 40) . '.webm';
    if (!is_dir($dataDir . '/plugin-media/hub')) mkdir($dataDir . '/plugin-media/hub', 0770, true);
    file_put_contents($dataDir . '/plugin-media/hub/' . $pluginMediaName, 'private Hub media canary');

    $archive = createBackup($archives, $dataDir, 10);
    verify(is_file($archive) && (fileperms($archive) & 0777) === 0600, 'archive is created with owner-only permissions');
    $manifest = inspectArchive($archive);
    verify(($manifest['format'] ?? '') === 'n3-backup' && ($manifest['version'] ?? 0) === 1, 'versioned manifest is readable');
    verify(
        ($manifest['app_version'] ?? '') === trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'))
            && ($manifest['schema_version'] ?? -1) === supportedSchemaVersion(),
        'manifest records application and schema versions',
    );
    verify(($manifest['counts']['revisions'] ?? 0) >= 3, 'manifest records revision counts');
    verify(isset($manifest['media'][$mediaName]), 'manifest records uploaded media checksums');
    verify(isset($manifest['avatars'][$avatarName]), 'manifest records referenced avatar checksums');
    verify(!isset($manifest['avatars'][$orphanAvatarName]), 'manifest excludes unreferenced avatar files from the profile backup boundary');
    verify(isset($manifest['plugin_media']['hub'][$pluginMediaName]), 'manifest records authenticated plugin media checksums by plugin namespace');

    $restoredManifest = restoreBackup($archive, $fresh);
    verify($restoredManifest['database_sha256'] === $manifest['database_sha256'], 'fresh-directory import preserves the validated snapshot');
    verify(file_get_contents($fresh . '/media/' . $mediaName) === 'backup media canary', 'uploaded media survives the backup round trip');
    verify(file_get_contents($fresh . '/avatars/' . $avatarName) === 'backup avatar canary', 'profile avatars survive the backup round trip');
    verify(file_get_contents($fresh . '/plugin-media/hub/' . $pluginMediaName) === 'private Hub media canary', 'authenticated plugin media survives the backup round trip');
    $restored = openBackupDatabase(backupDatabasePath($fresh));
    verify(
        $restored->query('SELECT value FROM backup_probe_records WHERE id = 1')->fetchColumn() === 'plugin-owned backup canary'
            && $restored->query("SELECT checksum FROM plugin_migrations WHERE plugin_id = 'backup-probe' AND migration = 1")->fetchColumn() === str_repeat('a', 64),
        'plugin-owned tables and migration ledger records survive the full SQLite backup round trip',
    );
    $private = $restored->query("SELECT parent_id, content, is_public, content_revision, author_id, last_editor_id, first_published_at FROM pages WHERE slug = 'private-archive-page-test'")->fetch();
    $public = $restored->query("SELECT parent_id, content, is_public, author_id, last_editor_id, first_published_at FROM pages WHERE slug = 'public-archive-page-test'")->fetch();
    verify((int)$private['is_public'] === 0 && str_contains($private['content'], 'private backup canary'), 'private page remains private with its content intact');
    verify((int)$public['is_public'] === 1 && str_contains($public['content'], 'public backup canary'), 'public visibility survives the round trip');
    verify((int)$private['parent_id'] === $folderId && (int)$public['parent_id'] === $folderId, 'space and folder hierarchy survives the round trip');
    verify(
        (int)$private['author_id'] === $ownerId
            && (int)$private['last_editor_id'] === $ownerId
            && $private['first_published_at'] === null
            && (int)$public['author_id'] === $ownerId
            && (int)$public['last_editor_id'] === $ownerId
            && $public['first_published_at'] === '2026-07-25 12:00:00',
        'page authorship and first-publication metadata survive the round trip',
    );
    $revisionCount = (int)$restored->query("SELECT COUNT(*) FROM page_revisions WHERE page_id = $privateId")->fetchColumn();
    verify($revisionCount === 2 && (int)$private['content_revision'] === 2, 'complete revision history survives the round trip');
    $tagCount = (int)$restored->query("SELECT COUNT(*) FROM page_tags JOIN tags ON tags.id = page_tags.tag_id WHERE tags.name = 'round-trip-tag'")->fetchColumn();
    verify($tagCount === 2, 'tags and page relationships survive the round trip');
    $referenceCount = (int)$restored->query("SELECT COUNT(*) FROM page_references WHERE page_id = $privateId AND label = 'Backup reference'")->fetchColumn();
    $shareCount = (int)$restored->query("SELECT COUNT(*) FROM resource_shares WHERE resource_type = 'page' AND resource_id = $privateId AND user_id = $readerId AND role = 'viewer'")->fetchColumn();
    verify($referenceCount === 1 && $shareCount === 1, 'references, collaborator accounts, and access grants survive the round trip');
    $readerProfile = $restored->query("SELECT username, display_name, profile_slug, profile_visibility, avatar_reference FROM users WHERE id = $readerId")->fetch();
    verify(
        $readerProfile === ['username' => 'backup-renamed-reader', 'display_name' => 'Backup Reader', 'profile_slug' => 'backup-reader-' . $readerId, 'profile_visibility' => 'members', 'avatar_reference' => $avatarName],
        'renamed profile identity, stable slug, visibility, and avatar reference survive the round trip',
    );
    unset($restored);

    $changed = openBackupDatabase(backupDatabasePath($fresh));
    $changed->exec("UPDATE pages SET content = '<p>damaged after backup</p>' WHERE slug = 'private-archive-page-test'");
    unset($changed);
    restoreBackup($archive, $fresh);
    verify(count(glob($fresh . '/n3-pre-restore-*.sqlite') ?: []) === 1, 'restore creates a pre-restore safety copy');
    $restoredAgain = openBackupDatabase(backupDatabasePath($fresh));
    verify(str_contains((string)$restoredAgain->query("SELECT content FROM pages WHERE slug = 'private-archive-page-test'")->fetchColumn(), 'private backup canary'), 'validated restore recovers modified data');
    unset($restoredAgain);

    $corruptTar = $work . '/corrupt.tar';
    $corruptDatabase = $work . '/corrupt.sqlite';
    file_put_contents($corruptDatabase, 'not the expected database');
    $corrupt = new PharData($corruptTar);
    $corrupt->addFile($corruptDatabase, 'n3.sqlite');
    $corrupt->addFromString('manifest.json', file_get_contents('phar://' . $archive . '/manifest.json'));
    unset($corrupt);
    $corruptInput = fopen($corruptTar, 'rb');
    $corruptOutput = gzopen($corruptTar . '.gz', 'wb9');
    while (!feof($corruptInput)) gzwrite($corruptOutput, (string)fread($corruptInput, 1024 * 1024));
    fclose($corruptInput);
    gzclose($corruptOutput);
    $rejected = false;
    try { restoreBackup($corruptTar . '.gz', $work . '/rejected'); } catch (RuntimeException $error) { $rejected = str_contains($error->getMessage(), 'checksum') || str_contains($error->getMessage(), 'media file is missing'); }
    verify($rejected, 'checksum mismatch is rejected before import');

    foreach (range(1, 4) as $index) {
        $file = $archives . "/n3-rotation-$index.tar.gz";
        file_put_contents($file, 'test');
        touch($file, time() + $index);
    }
    rotateBackups($archives, 2);
    verify(count(glob($archives . '/n3-*.tar.gz') ?: []) === 2, 'backup rotation keeps only the configured number of archives');

    echo "\nn3 backup round-trip test passed.\n";
} finally {
    removeDirectory($work);
}
