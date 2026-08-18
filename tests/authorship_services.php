<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PageReferenceRepository;
use N3\Repository\PageRepository;
use N3\Repository\RevisionRepository;
use N3\Repository\TagRepository;
use N3\Repository\TrashRepository;

function verifyAuthorship(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec('PRAGMA foreign_keys = ON');
$database->exec(<<<'SQL'
    CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT NOT NULL);
    CREATE TABLE spaces (id INTEGER PRIMARY KEY, owner_id INTEGER, name TEXT NOT NULL);
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, space_id INTEGER NOT NULL, parent_id INTEGER,
        author_id INTEGER, last_editor_id INTEGER, title TEXT NOT NULL, slug TEXT,
        kind TEXT NOT NULL DEFAULT 'page', content TEXT NOT NULL DEFAULT '<p></p>',
        position INTEGER NOT NULL DEFAULT 0, is_favorite INTEGER NOT NULL DEFAULT 0,
        is_public INTEGER NOT NULL DEFAULT 0, is_deleted INTEGER NOT NULL DEFAULT 0,
        content_revision INTEGER NOT NULL DEFAULT 1, first_published_at TEXT,
        feature_image TEXT, feature_image_opacity INTEGER NOT NULL DEFAULT 50,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE page_revisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, revision INTEGER NOT NULL,
        title TEXT NOT NULL, content TEXT NOT NULL, source TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE(page_id, revision)
    );
    CREATE TABLE tags (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE COLLATE NOCASE);
    CREATE TABLE page_tags (page_id INTEGER NOT NULL, tag_id INTEGER NOT NULL, PRIMARY KEY(page_id, tag_id));
    CREATE TABLE page_references (
        id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL,
        label TEXT NOT NULL, url TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0
    );
    INSERT INTO users VALUES (1, 'author'), (2, 'editor'), (3, 'collaborator'), (4, 'restorer');
    INSERT INTO spaces VALUES (1, 1, 'First'), (2, 2, 'Second');
SQL);

$pages = new PageRepository($database);
$pageId = $pages->create(1, null, 'Authored page', 'page', 'authored-page', 1);
$childId = $pages->create(1, $pageId, 'Child', 'page', 'child', 1);
$created = $pages->find($pageId);
verifyAuthorship((int)$created['author_id'] === 1 && (int)$created['last_editor_id'] === 1, 'page creation assigns the current actor as author and last editor');

$pages->update($pageId, ['title' => 'Published page', 'is_public' => 1], false, 0, 1, 1, 2, true);
$published = $pages->find($pageId);
$firstPublishedAt = $published['first_published_at'];
verifyAuthorship((int)$published['author_id'] === 1 && (int)$published['last_editor_id'] === 2 && $firstPublishedAt !== null, 'first publication preserves authorship, records the editor, and stamps publication');

$database->exec("UPDATE pages SET first_published_at = '2026-04-05 06:07:08' WHERE id = $pageId");
$pages->update($pageId, ['is_public' => 0], false, 0, 1, 1, 3);
$pages->update($pageId, ['is_public' => 1], false, 0, 1, 1, 2, true);
verifyAuthorship($pages->find($pageId)['first_published_at'] === '2026-04-05 06:07:08', 'later publication transitions never replace the first-published timestamp');

$pages->update($pageId, ['is_favorite' => 1], false, 0, 1, 1, 4);
verifyAuthorship((int)$pages->find($pageId)['last_editor_id'] === 2, 'personal favorite changes do not claim page editorship');

(new TagRepository($database))->replaceForPage($pageId, ['profile'], 3);
verifyAuthorship((int)$pages->find($pageId)['last_editor_id'] === 3, 'tag writes record the current actor as last editor');
(new PageReferenceRepository($database))->replaceForPage($pageId, [['label' => 'Source', 'url' => 'https://example.com']], 2);
verifyAuthorship((int)$pages->find($pageId)['last_editor_id'] === 2, 'reference writes record the current actor as last editor');

$duplicateId = $pages->duplicate($pages->find($pageId), 'published-page-copy', 3);
$duplicate = $pages->find($duplicateId);
verifyAuthorship((int)$duplicate['author_id'] === 3 && (int)$duplicate['last_editor_id'] === 3 && $duplicate['first_published_at'] === null, 'duplicates belong to the duplicating actor and start unpublished');

$pages->update($pageId, ['space_id' => 2], false, 0, 1, 2, 2);
verifyAuthorship((int)$pages->find($pageId)['last_editor_id'] === 2 && (int)$pages->find($childId)['last_editor_id'] === 2, 'cross-space moves record the actor across the moved subtree');

$snapshot = (new RevisionRepository($database))->snapshot($pageId, 1);
$restored = (new RevisionRepository($database))->restore($pageId, $snapshot, 1, 4);
verifyAuthorship($restored !== null && (int)$pages->find($pageId)['last_editor_id'] === 4, 'revision restores record the restoring actor');

$pages->softDeleteTree($pageId, 2);
verifyAuthorship((int)$pages->find($childId, true)['last_editor_id'] === 2, 'soft deletion records the actor across the deleted subtree');
(new TrashRepository($database))->restoreTree($pageId, 3);
verifyAuthorship((int)$pages->find($pageId)['last_editor_id'] === 3 && (int)$pages->find($childId)['last_editor_id'] === 3, 'trash restoration records the actor across the restored subtree');

echo "\nn3 authorship mutation test passed.\n";
