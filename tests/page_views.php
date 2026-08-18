<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\View\ViewRenderer;
use N3\View\PageDiscoveryView;
use N3\View\PageInformationView;

function verifyPageView(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$views = new ViewRenderer(dirname(__DIR__) . '/views');
$page = [
    'id' => 42,
    'slug' => 'title-unsafe-42',
    'title' => 'Title <unsafe>',
    'content' => '<p>Trusted stored content</p>',
];

$preview = $views->render('page/preview', [
    'content' => '<p>Trusted sanitized preview</p>',
    'page' => $page,
    'visibility' => 'Private preview',
]);
verifyPageView(str_contains($preview, 'Title &lt;unsafe&gt;') && !str_contains($preview, 'Title <unsafe>'), 'preview escapes page titles');
verifyPageView(str_contains($preview, 'href="/page/title-unsafe-42"') && str_contains($preview, 'Private preview'), 'preview preserves canonical editor return link and visibility');
verifyPageView(str_contains($preview, '<p>Trusted sanitized preview</p>'), 'preview renders content sanitized by its caller');

$export = $views->render('page/export', ['page' => $page]);
verifyPageView(str_contains($export, '<title>Title &lt;unsafe&gt;</title>') && str_contains($export, '<h1>Title &lt;unsafe&gt;</h1>'), 'HTML export escapes titles in metadata and content');
verifyPageView(str_contains($export, '<p>Trusted stored content</p>'), 'HTML export preserves stored rich text');

$privateDiscovery = PageDiscoveryView::render([], [[
    'id' => 7,
    'slug' => 'related-page-7',
    'title' => 'Related page',
    'shared_tags' => 2,
]], false);
verifyPageView(str_contains($privateDiscovery, 'href="/page/related-page-7"') && !str_contains($privateDiscovery, 'href="/page/7"'), 'private related pages use canonical editor slugs');

$information = PageInformationView::render([
    'author' => ['state' => 'visible', 'name' => 'Author <unsafe>', 'profile_url' => '/u/author-7', 'avatar_url' => '/avatar/author-7'],
    'word_count' => 1234,
    'created_at' => '2026-07-20T10:00:00Z',
    'first_published_at' => '2026-07-21T11:00:00Z',
    'updated_at' => '2026-07-25T12:00:00Z',
]);
verifyPageView(str_contains($information, 'Page information') && str_contains($information, '1,234'), 'page information renders its heading and formatted word count');
verifyPageView(str_contains($information, 'Author &lt;unsafe&gt;') && !str_contains($information, 'Author <unsafe>'), 'page information escapes author identity');
verifyPageView(str_contains($information, 'href="/u/author-7"') && str_contains($information, 'src="/avatar/author-7"'), 'visible authors render only their authorized profile and avatar URLs');
verifyPageView(str_contains($information, 'First published') && str_contains($information, '<time datetime="2026-07-25T12:00:00Z">Jul 25, 2026</time>'), 'page information renders semantic canonical created, published, and updated dates');

$pluginInformation = PageInformationView::render([
    'author' => ['state' => 'unknown', 'name' => 'Unknown author', 'profile_url' => null, 'avatar_url' => null],
    'word_count' => 1,
    'created_at' => null,
    'first_published_at' => null,
    'updated_at' => null,
    'plugin_rows' => [[
        'label' => 'State <unsafe>',
        'value' => '<script>text only</script>',
        'plugin_name' => 'Probe <plugin>',
    ]],
]);
verifyPageView(
    str_contains($pluginInformation, 'State &lt;unsafe&gt;')
        && str_contains($pluginInformation, '&lt;script&gt;text only&lt;/script&gt;')
        && str_contains($pluginInformation, 'Probe &lt;plugin&gt;')
        && !str_contains($pluginInformation, '<script>text only</script>'),
    'page-information contribution rows render as escaped attributed definition-list items',
);

$fallbackInformation = PageInformationView::render([
    'author' => ['state' => 'private', 'name' => 'Private author', 'profile_url' => null, 'avatar_url' => null],
    'word_count' => 0,
    'created_at' => null,
    'first_published_at' => null,
    'updated_at' => null,
]);
verifyPageView(str_contains($fallbackInformation, 'Private author') && !str_contains($fallbackInformation, 'href=') && !str_contains($fallbackInformation, 'First published'), 'hidden authors and absent publication dates render without links or metadata leaks');

echo "\nn3 page view test passed.\n";
