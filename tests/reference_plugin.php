<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Http\Request;
use N3\Plugin\PluginManager;
use N3\Plugin\PluginRegistry;

function verifyReferencePlugin(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$directory = dirname(__DIR__) . '/examples/plugins';
$publicDatabase = new PDO('sqlite::memory:');
$publicDatabase->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$publicDatabase->exec(<<<'SQL'
    CREATE TABLE users (
        id INTEGER PRIMARY KEY, username TEXT NOT NULL, display_name TEXT NOT NULL DEFAULT '',
        profile_slug TEXT, avatar_reference TEXT, is_admin INTEGER NOT NULL DEFAULT 0
    );
    INSERT INTO users VALUES (7, 'example', 'Example Account', 'example-7', 'avatar.webp', 0);
SQL);
$registry = new PluginRegistry();
$manager = new PluginManager($directory, $registry);
$discovered = $manager->discover();

verifyReferencePlugin(
    count($discovered) === 1
        && $discovered[0]['id'] === 'reference-plugin'
        && $discovered[0]['manifest_enabled'] === false
        && $discovered[0]['effective_enabled'] === false
        && $discovered[0]['status'] === 'disabled'
        && $discovered[0]['capabilities'] === [
            'php_bootstrap' => true,
            'public_routes' => true,
            'migrations' => 0,
            'dashboard_widgets' => 1,
            'navigation_items' => 1,
            'css_assets' => 1,
            'js_assets' => 1,
            'profile_tools' => true,
            'profile_cards' => true,
            'page_information' => true,
        ],
    'the non-production reference manifest is valid, disabled by default, and declares every supported contribution type',
);

$manager->applyEnablementOverrides(['reference-plugin' => true]);
$manager->boot($publicDatabase);
$plugin = $registry->plugins()[0] ?? null;
verifyReferencePlugin(
    $plugin !== null
        && $plugin['id'] === 'reference-plugin'
        && $plugin['css'] === ['/plugin-assets/reference-plugin/reference.css']
        && $plugin['js'] === ['/plugin-assets/reference-plugin/reference.js']
        && $plugin['navigation'] === [['label' => 'Reference', 'url' => '/api/plugins/reference-plugin/items/navigation', 'icon' => '◇']]
        && array_column($plugin['dashboard'], 'title') === ['Reference plugin'],
    'an explicit development override loads the dashboard widget and declared browser assets',
);

$publicResponse = $manager->publicResponse(new Request('GET', '/reference-plugin-public/status'), $publicDatabase);
verifyReferencePlugin(
    $publicResponse?->status() === 200
        && json_decode($publicResponse->body(), true) === ['plugin' => 'reference-plugin', 'public' => true],
    'the explicitly declared public hook handles only its anonymous prefix through the separate public registry',
);

$profileContext = [
    'surface' => 'profile',
    'audience' => 'self',
    'profile' => ['page_counts' => ['owned' => 2, 'shared' => 1]],
];
verifyReferencePlugin(
    array_column($registry->profileTools($profileContext), 'label') === ['Open profile report']
        && array_column($registry->profileCards($profileContext), 'title') === ['Profile summary']
        && array_column($registry->pageInformationRows(['page' => ['can_edit' => false]]), 'value') === ['Read only'],
    'the reference plugin exercises all declared structured contribution slots',
);

$read = $registry->dispatch(
    new Request(
        'GET',
        '/api/plugins/reference-plugin/items/example-42',
        ['view' => 'detail'],
        ['X-Reference-Trace' => 'reference-test'],
    ),
    ['id' => 7],
);
verifyReferencePlugin(
    $read?->status() === 200
        && json_decode($read->body(), true) === [
            'plugin' => 'reference-plugin',
            'item_id' => 'example-42',
            'view' => 'detail',
            'trace' => 'reference-test',
            'method' => 'GET',
            'user_id' => 7,
        ],
    'the authenticated read example uses route, query, header, user, and Response APIs',
);

$mutation = $registry->dispatch(
    new Request('POST', '/api/plugins/reference-plugin/items/example-42/events', [], [], '{"event":"reviewed"}'),
    ['id' => 7],
);
verifyReferencePlugin(
    $mutation?->status() === 200
        && json_decode($mutation->body(), true) === [
            'ok' => true,
            'item_id' => 'example-42',
            'event' => 'reviewed',
            'user_id' => 7,
        ],
    'the CSRF-gated mutation example uses route and JSON accessors plus array response normalization',
);

$invalid = $registry->dispatch(
    new Request('POST', '/api/plugins/reference-plugin/items/example-42/events', [], [], '[]'),
    ['id' => 7],
);
verifyReferencePlugin(
    $invalid?->status() === 400 && json_decode($invalid->body(), true) === ['error' => 'Expected a JSON object.'],
    'the reference mutation returns an explicit safe response for a non-object JSON body',
);

$account = $registry->dispatch(new Request('GET', '/api/plugins/reference-plugin/accounts/7'), ['id' => 7]);
verifyReferencePlugin(
    $account?->status() === 200
        && json_decode($account->body(), true)['account'] === [
            'id' => 7,
            'display_name' => 'Example Account',
            'profile_url' => '/u/example-7',
            'avatar_url' => '/avatar/example-7',
            'is_admin' => false,
        ],
    'the reference context exposes only display-safe account identity fields',
);

$upload = $registry->dispatch(
    new Request('POST', '/api/plugins/reference-plugin/uploads', [], [], '', [
        'example' => ['name' => 'example.png', 'type' => 'image/png', 'tmp_name' => '/tmp/example', 'error' => 0, 'size' => 42],
    ]),
    ['id' => 7],
);
verifyReferencePlugin(
    $upload?->status() === 200 && json_decode($upload->body(), true) === ['name' => 'example.png', 'size' => 42],
    'the reference upload route uses the normalized file accessor without PHP globals',
);

$css = $manager->asset('reference-plugin', 'reference.css');
$js = $manager->asset('reference-plugin', 'reference.js');
verifyReferencePlugin(
    $css !== null
        && $js !== null
        && $css['mime'] === 'text/css; charset=utf-8'
        && $js['mime'] === 'text/javascript; charset=utf-8'
        && str_contains((string)file_get_contents($css['path']), 'reference-plugin')
        && str_contains((string)file_get_contents($js['path']), 'referencePlugin'),
    'the reference CSS and JavaScript resolve only through their declared asset types',
);

echo "\nn3 reference plugin test passed.\n";
