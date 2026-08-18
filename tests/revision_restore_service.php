<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PageRepository;
use N3\Repository\RevisionRepository;
use N3\Service\DomainException;
use N3\Service\RevisionRestoreService;

function verifyRestore(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function expectRestoreError(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        verifyRestore($error->status() === $status && $error->getMessage() === $message, "$status $message");
        return;
    }
    throw new RuntimeException("Expected domain error: $message");
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY, title TEXT, content TEXT, kind TEXT, is_deleted INTEGER DEFAULT 0,
        content_revision INTEGER DEFAULT 1, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE page_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, revision INTEGER, title TEXT, content TEXT,
        source TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(page_id, revision)
    );
    INSERT INTO pages VALUES
        (1, 'Current title', '<p>Current</p>', 'page', 0, 2, '2026-01-02'),
        (2, 'Folder', '<p></p>', 'folder', 0, 1, '2026-01-02'),
        (3, 'Deleted', '<p>Deleted</p>', 'page', 1, 1, '2026-01-02');
    INSERT INTO page_revisions (page_id, revision, title, content, source) VALUES
        (1, 1, 'Original title', '<p>Original</p>', 'initial'),
        (1, 2, 'Current title', '<p>Current</p>', 'edit');
SQL);

$pages = new PageRepository($database);
$revisions = new RevisionRepository($database);
$service = new RevisionRestoreService($pages, $revisions);

expectRestoreError(fn() => $service->restore(99, 1, 1), 404, 'Page not found.');
expectRestoreError(fn() => $service->restore(3, 1, 1), 404, 'Page not found.');
expectRestoreError(fn() => $service->restore(2, 1, 1), 422, 'Folders do not have revision history.');
expectRestoreError(fn() => $service->restore(1, 1, 0), 428, 'A base revision is required to restore content.');
expectRestoreError(fn() => $service->restore(1, 99, 2), 404, 'Revision not found.');

$result = $service->restore(1, 1, 2);
$page = $pages->find(1);
verifyRestore($result['ok'] === true && $result['restored_from'] === 1 && (int)$result['content_revision'] === 3, 'restore returns the existing API result contract');
verifyRestore($page['title'] === 'Original title' && $page['content'] === '<p>Original</p>', 'restore applies the selected immutable snapshot');
$head = $revisions->find(1, 3);
verifyRestore($head !== null && $head['source'] === 'restore', 'restore appends a new head revision without removing later history');

expectRestoreError(fn() => $service->restore(1, 2, 2), 409, 'This page changed in another session. Refresh history before restoring.');
verifyRestore((int)$pages->find(1)['content_revision'] === 3, 'stale restore leaves the current head unchanged');

echo "\nn3 revision restore service test passed.\n";
