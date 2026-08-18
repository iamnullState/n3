<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PageRepository;
use N3\Repository\SpaceRepository;
use N3\Service\DomainException;
use N3\Service\PageTreeService;

function verifyTree(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function expectTreeError(callable $operation, int $status, string $message): void
{
    try {
        $operation();
    } catch (DomainException $error) {
        verifyTree($error->status() === $status && $error->getMessage() === $message, "$status $message");
        return;
    }
    throw new RuntimeException("Expected domain error: $message");
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE spaces (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY, space_id INTEGER, parent_id INTEGER, title TEXT, is_deleted INTEGER DEFAULT 0,
        position INTEGER DEFAULT 0, kind TEXT DEFAULT 'page', slug TEXT, content TEXT DEFAULT '<p></p>',
        is_favorite INTEGER DEFAULT 0, is_public INTEGER DEFAULT 0, content_revision INTEGER DEFAULT 1,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    );
    INSERT INTO spaces VALUES (1, 'First'), (2, 'Second');
    INSERT INTO pages (id, space_id, parent_id, title, position) VALUES
        (1, 1, NULL, 'Root', 0),
        (2, 1, 1, 'Child', 0),
        (3, 1, NULL, 'Sibling', 1),
        (4, 2, NULL, 'Other space', 0),
        (5, 1, NULL, 'Deleted parent', 2);
    UPDATE pages SET is_deleted = 1 WHERE id = 5;
SQL);

$pages = new PageRepository($database);
$service = new PageTreeService(new SpaceRepository($database), $pages);

$service->validatePlacement(1, null);
$service->validatePlacement(1, 1, 2);
verifyTree(true, 'valid root and same-space child placements pass');
expectTreeError(fn() => $service->validatePlacement(99, null), 404, 'Space not found.');
expectTreeError(fn() => $service->validatePlacement(1, 1, 1), 422, 'A page cannot be its own parent.');
expectTreeError(fn() => $service->validatePlacement(2, 1), 422, 'Parent page must be in the same space.');
expectTreeError(fn() => $service->validatePlacement(1, 2, 1), 422, 'A page cannot be moved beneath one of its descendants.');
expectTreeError(fn() => $service->validatePlacement(1, 5), 422, 'Parent page must be in the same space.');

expectTreeError(fn() => $service->reorder(0, 1, null, [1]), 422, 'Invalid tree reorder payload.');
expectTreeError(fn() => $service->reorder(99, 1, null, [99]), 404, 'Page not found.');
expectTreeError(fn() => $service->reorder(1, 1, null, [1]), 409, 'The directory changed. Refresh and try the move again.');

$service->reorder(1, 2, null, [4, 1]);
verifyTree((int)$pages->find(1)['space_id'] === 2 && (int)$pages->find(2)['space_id'] === 2, 'cross-space reorder moves the complete subtree');
verifyTree((int)$pages->find(4)['position'] === 0 && (int)$pages->find(1)['position'] === 1, 'successful reorder persists the supplied sibling order');

echo "\nn3 page tree service test passed.\n";
