<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PageRepository;
use N3\Repository\SpaceRepository;
use N3\Repository\TagRepository;

function verifyPrivateRead(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE spaces (id INTEGER PRIMARY KEY, name TEXT, description TEXT, icon TEXT, color TEXT, created_at TEXT, updated_at TEXT);
    CREATE TABLE pages (id INTEGER PRIMARY KEY, space_id INTEGER, parent_id INTEGER, title TEXT, slug TEXT, kind TEXT, content TEXT, position INTEGER, is_favorite INTEGER, is_public INTEGER, is_deleted INTEGER, content_revision INTEGER, created_at TEXT, updated_at TEXT);
    CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE);
    CREATE TABLE page_tags (page_id INTEGER, tag_id INTEGER);
    INSERT INTO spaces VALUES (1, 'Beta', '', 'book', '#222222', '2026-01-01', '2026-01-01'), (2, 'Alpha', '', 'book', '#111111', '2026-01-01', '2026-01-01');
    INSERT INTO pages VALUES
        (1, 1, NULL, 'Needle title', 'needle-title-1', 'page', '<p>Visible excerpt</p>', 1, 0, 0, 0, 2, '2026-01-01', '2026-01-03'),
        (2, 1, NULL, 'Other page', 'other-page-2', 'page', '<p>needle body</p>', 0, 0, 0, 0, 1, '2026-01-01', '2026-01-04'),
        (3, 2, NULL, 'Deleted needle', 'deleted-needle-3', 'page', '<p>hidden</p>', 2, 0, 0, 1, 1, '2026-01-01', '2026-01-05'),
        (4, 2, NULL, 'Needle folder', NULL, 'folder', '<p></p>', 3, 0, 0, 0, 1, '2026-01-01', '2026-01-06');
    INSERT INTO tags VALUES (1, 'alpha'), (2, 'Beta');
    INSERT INTO page_tags VALUES (1, 2), (1, 1);
SQL);

$spaces = new SpaceRepository($database);
verifyPrivateRead(array_column($spaces->all(), 'name') === ['Alpha', 'Beta'], 'spaces are returned in workspace display order');
verifyPrivateRead($spaces->exists(1) && !$spaces->exists(99) && $spaces->count() === 2, 'space existence and count queries are encapsulated');

$pages = new PageRepository($database);
verifyPrivateRead($pages->find(1)['title'] === 'Needle title' && $pages->find(3) === null, 'normal page lookup excludes trash');
verifyPrivateRead($pages->find(3, true)['title'] === 'Deleted needle', 'trash-aware page lookup includes deleted records');
verifyPrivateRead(array_column($pages->active(), 'id') === [2, 1, 4], 'workspace bootstrap returns active pages in tree order');
$results = $pages->search('needle');
verifyPrivateRead(array_column($results, 'id') === [1, 2], 'private search prioritizes title matches and excludes folders and trash');
verifyPrivateRead($results[1]['excerpt'] === 'needle body', 'private search returns plain-text excerpts');

$tags = new TagRepository($database);
verifyPrivateRead($tags->forPage(1) === ['alpha', 'Beta'], 'page tags are returned case-insensitively sorted');

echo "\nn3 private read repository test passed.\n";
