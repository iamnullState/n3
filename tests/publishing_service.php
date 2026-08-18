<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PublicPageRepository;
use N3\Service\HtmlSanitizer;
use N3\Service\PublishingService;

function verifyPublishing(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE pages (id INTEGER PRIMARY KEY, slug TEXT, title TEXT, content TEXT, author_id INTEGER, feature_image TEXT, feature_image_opacity INTEGER DEFAULT 50, created_at TEXT, first_published_at TEXT, updated_at TEXT, kind TEXT, is_public INTEGER, is_deleted INTEGER);
    CREATE TABLE spaces (id INTEGER PRIMARY KEY, name TEXT, color TEXT);
    CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT);
    CREATE TABLE page_tags (page_id INTEGER, tag_id INTEGER);
    INSERT INTO pages (id, slug, title, content, author_id, created_at, first_published_at, updated_at, kind, is_public, is_deleted) VALUES
        (1, 'published-target', 'Published', '<p>Public</p>', NULL, '2026-01-01', '2026-01-01', '2026-01-01', 'page', 1, 0),
        (2, 'private-target', 'Private', '<p>Private</p>', NULL, '2026-01-01', NULL, '2026-01-01', 'page', 0, 0),
        (3, 'deleted-target', 'Deleted', '<p>Deleted</p>', NULL, '2026-01-01', '2026-01-01', '2026-01-01', 'page', 1, 1);
SQL);

$publishing = new PublishingService(new PublicPageRepository($database), new HtmlSanitizer());
verifyPublishing($publishing->visibilityFor(['kind' => 'page'], 1) === 1, 'publishing accepts the explicit public state');
verifyPublishing($publishing->visibilityFor(['kind' => 'page'], true) === 1, 'publishing normalizes boolean public state');
verifyPublishing($publishing->visibilityFor(['kind' => 'page'], 0) === 0 && $publishing->visibilityFor(['kind' => 'page'], 99) === 0, 'publishing normalizes all non-public values to private');
verifyPublishing($publishing->visibilityFor(['kind' => 'folder'], 1) === null, 'folders cannot acquire page visibility');

$content = $publishing->content('<p onclick="bad()"><a href="/page/1">Legacy public</a> <a href="/page/published-target">Slug public</a> <a href="/page/2">Private</a> <a href="/page/deleted-target">Deleted</a></p>');
verifyPublishing(substr_count($content, 'href="/p/published-target"') === 2, 'numeric and slugged editor links are rewritten to stable public URLs');
verifyPublishing(!str_contains($content, '/page/2') && !str_contains($content, '/page/deleted-target'), 'private and deleted internal link targets are not exposed');
verifyPublishing(!str_contains($content, 'onclick='), 'published content is sanitized before link resolution');

echo "\nn3 publishing service test passed.\n";
