<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\View\ViewRenderer;

function verifyProfileView(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$views = new ViewRenderer(dirname(__DIR__) . '/views');
$page = [
    'id' => 10,
    'slug' => 'safe-page-10',
    'title' => 'Safe <page>',
    'is_public' => 1,
    'created_at' => '2026-01-01 00:00:00',
    'updated_at' => '2026-01-02 00:00:00',
    'first_published_at' => '2026-01-02 00:00:00',
    'url' => '/page/safe-page-10',
];
$base = [
    'id' => 1,
    'username' => 'owner<script>',
    'display_name' => 'Owner <Name>',
    'biography' => "Line one\n<script>alert(1)</script>",
    'profile_slug' => 'owner-1',
    'profile_visibility' => 'private',
    'has_avatar' => true,
    'audience' => 'self',
    'is_self' => true,
    'pages' => ['owned' => [$page], 'shared' => [], 'published' => [$page]],
    'counts' => ['owned' => 1, 'shared' => 0, 'published' => 1],
];

$self = $views->render('profile/show', [
    'appName' => 'Test <wiki>',
    'canonical' => 'https://example.test/u/owner-1',
    'profile' => $base,
]);
verifyProfileView(
    str_contains($self, 'Owner &lt;Name&gt;')
        && str_contains($self, '&lt;script&gt;alert(1)&lt;/script&gt;')
        && !str_contains($self, '<script>alert(1)</script>'),
    'profile identity, biography, and configuration values are escaped',
);
verifyProfileView(
    str_contains($self, 'Owned pages')
        && str_contains($self, 'Shared with me')
        && str_contains($self, 'Published by me')
        && substr_count($self, '/page/safe-page-10') === 2,
    'self profiles render owned, shared, and deliberately overlapping published groups with editor links',
);
verifyProfileView(str_contains($self, '<meta name="robots" content="noindex,nofollow">') && str_contains($self, '/avatar/owner-1'), 'self profiles render no-index metadata and their authorized avatar route');

$pluginProfile = $base;
$pluginProfile['plugin_contributions'] = [
    'tools' => [[
        'label' => 'Tool <unsafe>',
        'url' => '/api/plugins/profile-probe/tool?value=%22unsafe%22',
        'plugin_name' => 'Profile probe',
    ]],
    'cards' => [[
        'title' => 'Card <unsafe>',
        'body' => '<script>text only</script>',
        'url' => '/api/plugins/profile-probe/card',
        'plugin_name' => 'Probe <plugin>',
    ]],
];
$pluginHtml = $views->render('profile/show', ['appName' => 'Test wiki', 'canonical' => 'https://example.test/u/owner-1', 'profile' => $pluginProfile]);
verifyProfileView(
    str_contains($pluginHtml, 'Tool &lt;unsafe&gt;')
        && str_contains($pluginHtml, 'Card &lt;unsafe&gt;')
        && str_contains($pluginHtml, '&lt;script&gt;text only&lt;/script&gt;')
        && str_contains($pluginHtml, 'Probe &lt;plugin&gt;')
        && !str_contains($pluginHtml, '<script>text only</script>'),
    'signed-in profile contribution tools and cards render as escaped attributed text',
);

$visitorProfile = $base;
$visitorProfile['profile_visibility'] = 'members';
$visitorProfile['audience'] = 'signed_in';
$visitorProfile['is_self'] = false;
$visitorPage = $page;
$visitorPage['url'] = '/p/safe-page-10';
$visitorProfile['pages'] = ['authored' => [$visitorPage]];
$visitorProfile['counts'] = ['authored' => 1];
$visitor = $views->render('profile/show', ['appName' => 'Test wiki', 'canonical' => 'https://example.test/u/owner-1', 'profile' => $visitorProfile]);
verifyProfileView(str_contains($visitor, 'Pages you can view') && !str_contains($visitor, 'Owned pages') && str_contains($visitor, '/p/safe-page-10'), 'signed-in visitor profiles render only their authorized authored-page projection');

$publicProfile = $visitorProfile;
$publicProfile['profile_visibility'] = 'public';
$publicProfile['audience'] = 'public';
$publicProfile['pages'] = ['published' => [$visitorPage]];
$publicProfile['counts'] = ['published' => 1];
$publicProfile['plugin_contributions'] = $pluginProfile['plugin_contributions'];
$public = $views->render('profile/show', ['appName' => 'Test wiki', 'canonical' => 'https://example.test/u/owner-1', 'profile' => $publicProfile]);
verifyProfileView(
    str_contains($public, 'Published pages')
        && str_contains($public, '<link rel="canonical" href="https://example.test/u/owner-1">')
        && str_contains($public, '<meta property="og:type" content="profile">')
        && !str_contains($public, 'noindex,nofollow')
        && !str_contains($public, 'Tool &lt;unsafe&gt;')
        && !str_contains($public, 'Card &lt;unsafe&gt;'),
    'public profiles render canonical indexable metadata and defensively omit authenticated plugin contributions',
);

echo "\nn3 profile view test passed.\n";
