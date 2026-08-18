<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PublicPageRepository;
use N3\View\ViewRenderer;

function verifyPublicRead(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE spaces (id INTEGER PRIMARY KEY, name TEXT, color TEXT);
    CREATE TABLE pages (id INTEGER PRIMARY KEY, space_id INTEGER, parent_id INTEGER, slug TEXT, title TEXT, content TEXT, author_id INTEGER, feature_image TEXT, feature_image_opacity INTEGER DEFAULT 50, created_at TEXT, first_published_at TEXT, updated_at TEXT, kind TEXT, position INTEGER, is_public INTEGER, is_deleted INTEGER);
    CREATE TABLE tags (id INTEGER PRIMARY KEY, name TEXT COLLATE NOCASE);
    CREATE TABLE page_tags (page_id INTEGER, tag_id INTEGER);
    INSERT INTO spaces VALUES (1, 'Test <space>', '#123abc');
    INSERT INTO pages VALUES
        (1, 1, NULL, 'visible-page', 'Visible <page>', '<p>Public needle</p>', NULL, '/media/' || substr(hex(zeroblob(20)), 1, 40) || '.webp', 60, '2026-01-01 00:00:00', '2026-01-02 00:00:00', '2026-01-02 00:00:00', 'page', 0, 1, 0),
        (2, 1, NULL, 'private-page', 'Private page', '<p>Secret needle</p>', NULL, NULL, 50, '2026-01-01 00:00:00', NULL, '2026-01-03 00:00:00', 'page', 1, 0, 0),
        (3, 1, NULL, 'deleted-page', 'Deleted page', '<p>Public needle</p>', NULL, NULL, 50, '2026-01-01 00:00:00', '2026-01-04 00:00:00', '2026-01-04 00:00:00', 'page', 2, 1, 1),
        (4, 1, NULL, 'private-parent', 'Never public', '<p>Private parent</p>', NULL, NULL, 50, '2026-01-01 00:00:00', NULL, '2026-01-05 00:00:00', 'page', 3, 0, 0),
        (5, 1, 4, 'nested-public', 'Nested public', '<p>Visible child</p>', NULL, NULL, 50, '2026-01-01 00:00:00', '2026-01-06 00:00:00', '2026-01-06 00:00:00', 'page', 0, 1, 0);
    INSERT INTO tags VALUES (1, 'Guide'), (2, 'Private');
    INSERT INTO page_tags VALUES (1, 1), (2, 2);
SQL);

$repository = new PublicPageRepository($database);
verifyPublicRead(array_column($repository->search('needle'), 'slug') === ['visible-page'], 'public search excludes private and deleted pages');
verifyPublicRead(array_column($repository->search('', 'guide'), 'slug') === ['visible-page'], 'tag filtering is case-insensitive and public-only');
verifyPublicRead($repository->search('', 'private') === [], 'private tags cannot reveal private pages');
verifyPublicRead($repository->tags() === [['name' => 'Guide', 'page_count' => 1]], 'tag directory counts only published pages');
verifyPublicRead($repository->findBySlug('visible-page')['id'] === 1 && $repository->findBySlug('private-page') === null, 'public page lookup cannot return private pages');
verifyPublicRead($repository->slugForId(1) === 'visible-page' && $repository->slugForId(2) === null, 'legacy slug lookup cannot redirect private pages');
verifyPublicRead($repository->publishedSlugForEditorTarget('1') === 'visible-page' && $repository->publishedSlugForEditorTarget('visible-page') === 'visible-page' && $repository->publishedSlugForEditorTarget('private-page') === null, 'published editor targets resolve numeric and slugged links without exposing private pages');
verifyPublicRead($repository->tagsForPage(1) === ['Guide'], 'published page tags are returned in display order');

$views = new ViewRenderer(dirname(__DIR__) . '/views');
$home = $views->render('public/home', [
    'appName' => 'Test <wiki>',
    'directory' => '<aside>Directory</aside>',
    'pages' => $repository->search(),
    'query' => '<needle>',
    'tag' => 'guide',
]);
verifyPublicRead(str_contains($home, 'Test &lt;wiki&gt;') && !str_contains($home, 'Test <wiki>'), 'public home escapes configuration and query values');
verifyPublicRead(str_contains($home, 'Visible &lt;page&gt;') && str_contains($home, '<aside>Directory</aside>'), 'public home escapes records and includes trusted directory markup');

$tags = $views->render('public/tags', [
    'appName' => 'Test wiki',
    'directory' => '<aside>Directory</aside>',
    'tags' => $repository->tags(),
]);
verifyPublicRead(str_contains($tags, 'Guide') && str_contains($tags, '1 page'), 'tag template renders public tag counts');

$directory = $views->render('public/directory', $repository->directory() + ['currentSlug' => 'visible-page']);
verifyPublicRead(str_contains($directory, 'Test &lt;space&gt;') && str_contains($directory, 'Nested public'), 'directory renders published pages and escaped ancestors');
verifyPublicRead(!str_contains($directory, 'Never public') && str_contains($directory, 'public-directory-page active'), 'directory hides private page ancestors and marks the current page');
$directoryData = $repository->directory();
$directoryNodeIds = array_map('intval', array_column($directoryData['nodes'], 'id'));
sort($directoryNodeIds);
verifyPublicRead($directoryNodeIds === [1, 4, 5], 'public directory queries only published pages and the ancestors required to reach them');
$privateAncestor = array_values(array_filter($directoryData['nodes'], static fn(array $node): bool => (int)$node['id'] === 4))[0];
verifyPublicRead($privateAncestor['title'] === null && $privateAncestor['slug'] === null, 'private page ancestors retain traversal shape without exposing their title or URL');

$page = $repository->findBySlug('visible-page');
$article = $views->render('public/page', [
    'appName' => 'Test wiki',
    'canonical' => 'https://example.test/p/visible-page',
    'content' => '<p>Trusted sanitized content</p>',
    'description' => 'Public description',
    'directory' => $directory,
    'featureImage' => (new \N3\Service\FeatureImageService())->fromPage($page),
    'page' => $page,
    'tags' => $repository->tagsForPage((int)$page['id']),
]);
verifyPublicRead(str_contains($article, 'Visible &lt;page&gt;') && str_contains($article, 'https://example.test/p/visible-page'), 'public page template renders escaped metadata and canonical URL');
verifyPublicRead(
    str_contains($article, 'class="public-article has-feature-image"')
        && str_contains($article, '--feature-image: url(&quot;/media/' . str_repeat('0', 40) . '.webp&quot;)')
        && str_contains($article, '--feature-image-opacity: 0.6')
        && str_contains($article, '<div class="feature-image" aria-hidden="true"></div>'),
    'published pages render their feature image backdrop with the stored opacity',
);
verifyPublicRead(str_contains($article, 'property="og:image"'), 'published pages advertise their feature image as og:image');

$plainPage = $repository->findBySlug('nested-public');
$plainArticle = $views->render('public/page', [
    'appName' => 'Test wiki',
    'canonical' => 'https://example.test/p/nested-public',
    'content' => '<p>Trusted sanitized content</p>',
    'description' => 'Public description',
    'directory' => $directory,
    'featureImage' => (new \N3\Service\FeatureImageService())->fromPage($plainPage),
    'page' => $plainPage,
    'tags' => [],
]);
verifyPublicRead(
    !str_contains($plainArticle, 'has-feature-image')
        && !str_contains($plainArticle, 'feature-image')
        && !str_contains($plainArticle, 'og:image'),
    'pages without a feature image render no backdrop element, custom properties, or og:image',
);

echo "\nn3 public read-side test passed.\n";
