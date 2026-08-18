<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PageRepository;
use N3\Repository\RevisionRepository;
use N3\Repository\SpaceRepository;
use N3\Repository\TagRepository;
use N3\Repository\TrashRepository;

function verifyPersistence(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(<<<'SQL'
    CREATE TABLE spaces (
        id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
        icon TEXT NOT NULL DEFAULT 'book', color TEXT NOT NULL DEFAULT '#6d5dfc',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, space_id INTEGER NOT NULL, parent_id INTEGER, title TEXT NOT NULL,
        slug TEXT, kind TEXT NOT NULL DEFAULT 'page', content TEXT NOT NULL DEFAULT '<p></p>', position INTEGER NOT NULL DEFAULT 0,
        is_favorite INTEGER NOT NULL DEFAULT 0, is_public INTEGER NOT NULL DEFAULT 0, is_deleted INTEGER NOT NULL DEFAULT 0,
        content_revision INTEGER NOT NULL DEFAULT 1,
        feature_image TEXT, feature_image_opacity INTEGER NOT NULL DEFAULT 50,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY(space_id) REFERENCES spaces(id) ON DELETE CASCADE,
        FOREIGN KEY(parent_id) REFERENCES pages(id) ON DELETE SET NULL
    );
    CREATE TABLE page_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, revision INTEGER NOT NULL,
        title TEXT NOT NULL, content TEXT NOT NULL, source TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(page_id, revision), FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE
    );
    CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE COLLATE NOCASE);
    CREATE TABLE page_tags (
        page_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY(page_id, tag_id),
        FOREIGN KEY(page_id) REFERENCES pages(id) ON DELETE CASCADE,
        FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
    );
SQL);

$spaces = new SpaceRepository($database);
$firstSpace = $spaces->create('First', 'Description', 'book', '#111111');
$secondSpace = $spaces->create('Second', '', 'sparkles', '#222222');
$spaces->update($firstSpace, 'Renamed', 'Updated', '#333333');
verifyPersistence($spaces->all()[0]['name'] === 'Renamed' && $spaces->count() === 2, 'space repository creates and updates records');

$pages = new PageRepository($database);
$pageId = $pages->create($firstSpace, null, 'First page', 'page', 'first-page');
$folderId = $pages->create($firstSpace, null, 'Folder', 'folder', 'folder');
$childId = $pages->create($firstSpace, $pageId, 'Child', 'page', 'child');
verifyPersistence($pages->find($pageId)['slug'] === "first-page-$pageId", 'page creation assigns a stable id-based slug');
verifyPersistence((int)$pages->findBySlug("first-page-$pageId")['id'] === $pageId && $pages->findBySlug('missing-page') === null, 'page slugs resolve active editor routes');
verifyPersistence(count((new RevisionRepository($database))->forPage($pageId)) === 1, 'page creation records the initial revision');
verifyPersistence($pages->find($folderId)['slug'] === null, 'folder creation omits page-only persistence');
verifyPersistence($pages->parentSpace($childId) === $firstSpace && $pages->isDescendant($pageId, $childId), 'page hierarchy queries preserve ancestry boundaries');

$tags = new TagRepository($database);
$tags->replaceForPage($pageId, ['alpha', 'beta']);
$tags->replaceForPage($pageId, ['beta']);
verifyPersistence($tags->forPage($pageId) === ['beta'], 'tag replacement updates links and removes orphan tags atomically');

$saved = $pages->update($pageId, ['title' => 'Changed', 'content' => '<p>Version two</p>'], true, 1, $firstSpace, $firstSpace);
verifyPersistence((int)$saved['content_revision'] === 2 && (new RevisionRepository($database))->find($pageId, 2)['source'] === 'edit', 'content updates atomically append a revision');
verifyPersistence($pages->find($pageId)['slug'] === "first-page-$pageId", 'page title changes preserve the canonical slug');
$conflict = $pages->update($pageId, ['content' => '<p>stale write</p>'], true, 1, $firstSpace, $firstSpace);
verifyPersistence($conflict === null && $pages->find($pageId)['content'] === '<p>Version two</p>', 'stale content updates roll back without overwriting data');

$moved = $pages->update($pageId, ['space_id' => $secondSpace], false, 0, $firstSpace, $secondSpace);
verifyPersistence($moved !== null && (int)$pages->find($childId)['space_id'] === $secondSpace, 'cross-space updates move complete descendant trees');

$siblingId = $pages->create($secondSpace, null, 'Sibling', 'page', 'sibling');
verifyPersistence($pages->reorder($pageId, $secondSpace, $secondSpace, null, [$siblingId, $pageId]), 'tree reorder commits a complete sibling ordering');
verifyPersistence(!$pages->reorder($pageId, $secondSpace, $secondSpace, null, [$pageId]), 'tree reorder rejects stale incomplete sibling lists');

$duplicateId = $pages->duplicate($pages->find($pageId), 'changed-copy');
verifyPersistence($pages->find($duplicateId)['title'] === 'Changed copy' && (new RevisionRepository($database))->find($duplicateId, 1)['source'] === 'duplicate', 'page duplication creates its initial snapshot');

$revisionOne = (new RevisionRepository($database))->snapshot($pageId, 1);
$restored = (new RevisionRepository($database))->restore($pageId, $revisionOne, 2);
verifyPersistence((int)$restored['content_revision'] === 3 && $restored['title'] === 'First page', 'revision restore updates content and records a new revision');
verifyPersistence((new RevisionRepository($database))->restore($pageId, $revisionOne, 2) === null, 'revision restore detects stale base revisions');

$trash = new TrashRepository($database);
$pages->softDeleteTree($pageId);
verifyPersistence(count($trash->roots()) === 1 && $pages->find($childId) === null, 'soft deletion marks a complete subtree and lists only its root');
$trash->restoreTree($pageId);
verifyPersistence($pages->find($childId) !== null, 'trash restore revives a complete subtree');
$pages->softDeleteTree($pageId);
$trash->deleteTree($pageId);
verifyPersistence($pages->find($pageId, true) === null && $pages->find($childId, true) === null, 'permanent deletion removes a complete subtree');

$spaces->delete($firstSpace);
verifyPersistence(!$spaces->exists($firstSpace), 'space repository deletes records through the persistence boundary');

echo "\nn3 persistence mutation test passed.\n";
