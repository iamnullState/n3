<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\RevisionRepository;

function verifyRevision(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE page_revisions (
        id INTEGER PRIMARY KEY,
        page_id INTEGER,
        revision INTEGER,
        title TEXT,
        content TEXT,
        source TEXT,
        created_at TEXT
    );
    INSERT INTO page_revisions VALUES
        (1, 10, 1, 'First', '<p>short</p>', 'initial', '2026-01-01 00:00:00'),
        (2, 10, 2, 'Second', '<p>longer content</p>', 'edit', '2026-01-02 00:00:00'),
        (3, 20, 1, 'Other page', '<p>private boundary</p>', 'initial', '2026-01-03 00:00:00');
SQL);

$revisions = new RevisionRepository($database);
$history = $revisions->forPage(10);
verifyRevision(array_column($history, 'revision') === [2, 1], 'revision history is newest first and scoped to one page');
verifyRevision(!array_key_exists('content', $history[0]) && (int)$history[0]['content_size'] === strlen('<p>longer content</p>'), 'revision history exposes content size without full content');
verifyRevision(array_column($revisions->forPage(10, 1), 'revision') === [2], 'revision history honors its result limit');

$revision = $revisions->find(10, 1);
verifyRevision($revision !== null && $revision['content'] === '<p>short</p>', 'revision detail returns the requested snapshot');
verifyRevision($revisions->find(10, 3) === null && $revisions->find(20, 2) === null, 'revision detail cannot cross page or revision boundaries');

echo "\nn3 revision repository test passed.\n";
