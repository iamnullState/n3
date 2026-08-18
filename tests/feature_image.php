<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Repository\PageRepository;
use N3\Service\FeatureImageService;
use N3\Service\PageInformationService;
use N3\Service\PageProjectionService;
use N3\Repository\ProfileRepository;
use N3\View\ViewRenderer;

function verifyFeatureImage(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$service = new FeatureImageService();
$valid = '/media/' . str_repeat('a1', 20) . '.webp';

verifyFeatureImage($service->normalizePath($valid) === $valid, 'an uploaded n3 image path is accepted unchanged');
verifyFeatureImage($service->normalizePath('  ' . $valid . '  ') === $valid, 'surrounding whitespace is trimmed before validation');
foreach (['jpg', 'png', 'gif', 'webp', 'avif', 'bmp'] as $extension) {
    $path = '/media/' . str_repeat('0', 40) . '.' . $extension;
    verifyFeatureImage($service->normalizePath($path) === $path, "the $extension image extension is accepted");
}

$rejected = [
    'a video upload' => '/media/' . str_repeat('a1', 20) . '.mp4',
    'an off-site URL' => 'https://example.test/photo.png',
    'a traversal path' => '/media/../../etc/passwd',
    'an avatar path' => '/avatar/someone',
    'a brand asset path' => '/brand/banner',
    'a short digest' => '/media/abc.png',
    'an uppercase digest' => '/media/' . str_repeat('A1', 20) . '.png',
    'a query-string suffix' => $valid . '?x=1',
    'a javascript URL' => 'javascript:alert(1)',
    'a data URL' => 'data:image/png;base64,AAAA',
    'a non-string value' => 42,
    'an array value' => ['url' => $valid],
];
foreach ($rejected as $label => $value) {
    verifyFeatureImage($service->normalizePath($value) === null, "$label is rejected as a feature image");
}

verifyFeatureImage($service->clears(null) && $service->clears('') && $service->clears('   '), 'null and empty submissions clear the feature image');
verifyFeatureImage(!$service->clears($valid) && !$service->clears('nonsense'), 'a non-empty submission is never treated as a clear request');

verifyFeatureImage($service->normalizeOpacity(40) === 40 && $service->normalizeOpacity(60) === 60, 'the reviewed opacity band is preserved at both ends');
verifyFeatureImage($service->normalizeOpacity(0) === 40 && $service->normalizeOpacity(100) === 60, 'out-of-band opacity is clamped into 40–60%');
verifyFeatureImage($service->normalizeOpacity('55') === 55 && $service->normalizeOpacity(52.4) === 52, 'numeric strings and floats resolve to a bounded integer');
verifyFeatureImage($service->normalizeOpacity('bright') === 50 && $service->normalizeOpacity(null) === 50 && $service->normalizeOpacity(true) === 50, 'non-numeric opacity falls back to the 50% default');

verifyFeatureImage($service->fromPage(['feature_image' => $valid, 'feature_image_opacity' => 60]) === ['url' => $valid, 'opacity' => 60], 'a stored row projects into the view shape');
verifyFeatureImage($service->fromPage(['feature_image' => null]) === null, 'a page without a feature image projects to null');
verifyFeatureImage($service->fromPage(['feature_image' => 'https://example.test/x.png', 'feature_image_opacity' => 50]) === null, 'a corrupted stored path never reaches the view layer');
verifyFeatureImage($service->fromPage([]) === null, 'a row missing the columns entirely projects to null');

$style = $service->styleAttribute(['url' => $valid, 'opacity' => 40]);
verifyFeatureImage(str_contains($style, '--feature-image: url(&quot;' . $valid . '&quot;)') && str_contains($style, '--feature-image-opacity: 0.4'), 'the style attribute exposes both custom properties');
verifyFeatureImage($service->styleAttribute(null) === '', 'no style attribute is emitted without a feature image');

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->exec(<<<'SQL'
    CREATE TABLE spaces (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT);
    CREATE TABLE pages (
        id INTEGER PRIMARY KEY AUTOINCREMENT, space_id INTEGER, parent_id INTEGER,
        title TEXT, content TEXT, slug TEXT, kind TEXT DEFAULT 'page', position INTEGER DEFAULT 0,
        is_favorite INTEGER DEFAULT 0, is_public INTEGER DEFAULT 0, is_deleted INTEGER DEFAULT 0,
        content_revision INTEGER DEFAULT 1, feature_image TEXT, feature_image_opacity INTEGER DEFAULT 50,
        author_id INTEGER, last_editor_id INTEGER, first_published_at TEXT,
        created_at TEXT DEFAULT '2026-01-01 00:00:00', updated_at TEXT DEFAULT '2026-01-01 00:00:00'
    );
    CREATE TABLE page_revisions (id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, revision INTEGER, title TEXT, content TEXT, source TEXT, created_at TEXT DEFAULT '2026-01-01 00:00:00');
    CREATE TABLE profiles (user_id INTEGER PRIMARY KEY, display_name TEXT, slug TEXT, visibility TEXT, avatar_path TEXT);
    INSERT INTO spaces (id, name) VALUES (1, 'Space');
    INSERT INTO pages (id, space_id, title, content, slug) VALUES (1, 1, 'Post', '<p>Body</p>', 'post-1');
SQL);

$pages = new PageRepository($database);
$pages->update(1, ['feature_image' => $valid, 'feature_image_opacity' => 60], false, 0, 1, 1, null);
$stored = $pages->find(1);
verifyFeatureImage($stored['feature_image'] === $valid && (int)$stored['feature_image_opacity'] === 60, 'the repository persists both feature-image columns');

$projection = (new PageProjectionService(new PageInformationService(new ProfileRepository($database))))
    ->authenticatedDetail($stored, 1, []);
verifyFeatureImage($projection['feature_image'] === $valid && $projection['feature_image_opacity'] === 60, 'the authenticated projection exposes the feature image to the editor');

$pages->update(1, ['feature_image' => null], false, 0, 1, 1, null);
verifyFeatureImage($pages->find(1)['feature_image'] === null, 'clearing the feature image removes the stored path');

$pages->update(1, ['feature_image' => $valid], false, 0, 1, 1, null);
$duplicateId = $pages->duplicate($pages->find(1), 'post-copy', null);
verifyFeatureImage($pages->find($duplicateId)['feature_image'] === $valid, 'duplicating a page carries its feature image forward');

$thrown = false;
try {
    $pages->update(1, ['feature_image_url' => $valid], false, 0, 1, 1, null);
} catch (InvalidArgumentException) {
    $thrown = true;
}
verifyFeatureImage($thrown, 'the repository still rejects unsupported page fields');

$views = new ViewRenderer(dirname(__DIR__) . '/views');
$preview = $views->render('page/preview', [
    'content' => '<p>Trusted sanitized preview</p>',
    'featureImage' => ['url' => $valid, 'opacity' => 50],
    'page' => ['id' => 1, 'slug' => 'post-1', 'title' => 'Post'],
    'visibility' => 'Private preview',
]);
verifyFeatureImage(
    str_contains($preview, 'class="public-article has-feature-image"')
        && str_contains($preview, '--feature-image-opacity: 0.5')
        && str_contains($preview, '<div class="feature-image" aria-hidden="true"></div>'),
    'the private preview renders the same feature-image backdrop as the published post',
);

$plainPreview = $views->render('page/preview', [
    'content' => '<p>Trusted sanitized preview</p>',
    'page' => ['id' => 1, 'slug' => 'post-1', 'title' => 'Post'],
    'visibility' => 'Private preview',
]);
verifyFeatureImage(!str_contains($plainPreview, 'feature-image'), 'callers that supply no feature image render the original header markup');

echo "\nn3 feature image test passed.\n";
