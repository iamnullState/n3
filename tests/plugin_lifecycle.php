<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginManager;
use N3\Plugin\PluginRegistry;
use N3\Plugin\PublicPluginRegistry;

function verifyPluginLifecycle(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function pluginRouteRegistrationAccepted(string $path): bool
{
    $registry = new PluginRegistry();
    $registration = $registry->begin(['id' => 'bounded']);
    try {
        $registry->route('GET', $path, static fn(Request $request, array $user): array => []);
        return true;
    } catch (\N3\Plugin\PluginRegistrationException) {
        return false;
    } finally {
        $registry->discard($registration);
    }
}

$navigationRegistry = new PluginRegistry();
$navigationRegistration = $navigationRegistry->begin(['id' => 'navigation-probe', 'name' => 'Navigation probe']);
$navigationRegistry->navigationItem(['label' => "Open\nprobe", 'url' => '/api/plugins/navigation-probe/manage', 'icon' => '◇']);
$navigationRegistry->commit($navigationRegistration);
verifyPluginLifecycle(
    ($navigationRegistry->plugins()[0]['navigation'][0] ?? null) === [
        'label' => 'Open probe', 'url' => '/api/plugins/navigation-probe/manage', 'icon' => '◇',
    ],
    'structured navigation items are bounded, normalized, and retained by core',
);
$foreignNavigationRegistry = new PluginRegistry();
$foreignNavigationRegistration = $foreignNavigationRegistry->begin(['id' => 'navigation-probe']);
$foreignNavigationRejected = false;
try {
    $foreignNavigationRegistry->navigationItem(['label' => 'Unsafe', 'url' => '/api/plugins/another-plugin/manage']);
} catch (\N3\Plugin\PluginRegistrationException) {
    $foreignNavigationRejected = true;
} finally {
    $foreignNavigationRegistry->discard($foreignNavigationRegistration);
}
verifyPluginLifecycle($foreignNavigationRejected, 'navigation items cannot link through another plugin namespace');

$fixtureDirectory = __DIR__ . '/fixtures/plugins';
$errorLog = sys_get_temp_dir() . '/n3-plugin-lifecycle-' . bin2hex(random_bytes(5)) . '.log';
$previousErrorLog = ini_get('error_log');
ini_set('error_log', $errorLog);
unset($GLOBALS['n3_plugin_fixture_bootstrap_required']);

try {
    $registry = new PluginRegistry();
    $manager = new PluginManager($fixtureDirectory, $registry);
    $discovered = [];
    foreach ($manager->discover() as $plugin) $discovered[$plugin['id']] = $plugin;

    verifyPluginLifecycle(
        array_keys($discovered) === ['alpha-enabled', 'beta-disabled', 'delta-invalid-schema', 'eta-foreign-route', 'gamma-malformed', 'invalid+directory', 'iota-duplicate-route', 'theta-failing', 'zeta-healthy'],
        'manifest discovery is deterministic and includes enabled, disabled, and invalid plugins',
    );
    verifyPluginLifecycle(
        array_column($discovered, 'status', 'id') === [
            'alpha-enabled' => 'enabled',
            'beta-disabled' => 'disabled',
            'delta-invalid-schema' => 'invalid',
            'eta-foreign-route' => 'enabled',
            'gamma-malformed' => 'invalid',
            'invalid+directory' => 'invalid',
            'iota-duplicate-route' => 'enabled',
            'theta-failing' => 'enabled',
            'zeta-healthy' => 'enabled',
        ],
        'non-executable discovery assigns a sanitized pre-boot status to every manifest',
    );
    verifyPluginLifecycle(
        $registry->plugins() === []
            && !isset($GLOBALS['n3_plugin_fixture_bootstrap_required'])
            && $manager->discover() === array_values($discovered),
        'discovery is idempotent and does not register widgets, routes, or executable PHP',
    );
    verifyPluginLifecycle(
        $manager->asset('alpha-enabled', 'plugin.css') === null,
        'a discovered but not yet loaded plugin cannot serve browser assets',
    );
    verifyPluginLifecycle(
        $discovered['delta-invalid-schema']['diagnostic'] === 'Plugin manifest field "enabled" must be boolean.'
            && $discovered['gamma-malformed']['diagnostic'] === 'Plugin manifest is not valid JSON.'
            && $discovered['invalid+directory']['diagnostic'] === 'Plugin directory ID is invalid.',
        'invalid manifests expose fixed validation diagnostics without parser or filesystem details',
    );

    $manager->boot();
    $booted = [];
    foreach ($manager->inventory() as $plugin) $booted[$plugin['id']] = $plugin;
    $plugins = [];
    foreach ($registry->plugins() as $plugin) $plugins[$plugin['id']] = $plugin;

    verifyPluginLifecycle(
        array_keys($plugins) === ['alpha-enabled', 'zeta-healthy'],
        'boot commits only successful enabled plugins in deterministic directory order',
    );
    verifyPluginLifecycle(
        ($GLOBALS['n3_plugin_fixture_bootstrap_required'] ?? []) === ['alpha-enabled'],
        'executable bootstrap files are required only during the boot phase',
    );
    verifyPluginLifecycle(
        array_column($booted, 'status', 'id') === [
            'alpha-enabled' => 'loaded',
            'beta-disabled' => 'disabled',
            'delta-invalid-schema' => 'invalid',
            'eta-foreign-route' => 'failed',
            'gamma-malformed' => 'invalid',
            'invalid+directory' => 'invalid',
            'iota-duplicate-route' => 'failed',
            'theta-failing' => 'failed',
            'zeta-healthy' => 'loaded',
        ],
        'boot transitions enabled definitions to loaded or failed without changing disabled and invalid statuses',
    );
    verifyPluginLifecycle(
        $booted['eta-foreign-route']['diagnostic'] === 'Plugin route is outside its own API namespace.'
            && $booted['iota-duplicate-route']['diagnostic'] === 'Plugin route duplicates an existing method and path.'
            && $booted['theta-failing']['diagnostic'] === 'Plugin bootstrap failed. Check the application log.'
            && !str_contains($booted['theta-failing']['diagnostic'], 'expected fixture bootstrap failure'),
        'route boundary failures are explicit while arbitrary bootstrap failures remain sanitized',
    );
    verifyPluginLifecycle(
        $plugins['alpha-enabled']['name'] === 'Alpha enabled'
            && $plugins['alpha-enabled']['version'] === '1.2.3'
            && count($plugins['alpha-enabled']['css']) === 1
            && count($plugins['alpha-enabled']['js']) === 1,
        'a valid manifest exposes normalized identity and existing declared CSS/JavaScript assets',
    );
    verifyPluginLifecycle(
        array_column($plugins['alpha-enabled']['dashboard'], 'title') === ['Manifest card', 'Bootstrap card'],
        'manifest and PHP bootstrap dashboard widgets retain registration order',
    );
    verifyPluginLifecycle(
        ($booted['alpha-enabled']['capabilities']['profile_tools'] ?? false) === true
            && ($booted['alpha-enabled']['capabilities']['profile_cards'] ?? false) === true
            && ($booted['alpha-enabled']['capabilities']['page_information'] ?? false) === true,
        'inventory exposes each validated manifest-declared contribution slot',
    );
    $profileTools = $registry->profileTools(['audience' => 'self']);
    $profileCards = $registry->profileCards(['audience' => 'self']);
    $informationRows = $registry->pageInformationRows(['page' => ['can_edit' => false]]);
    verifyPluginLifecycle(
        ($profileTools[0]['label'] ?? null) === 'Inspect profile'
            && array_column($profileCards, 'title') === ['Healthy card']
            && ($informationRows[0]['value'] ?? null) === 'Read only'
            && !in_array('Disabled profile card', array_column($profileCards, 'title'), true)
            && !in_array('Failed profile card', array_column($profileCards, 'title'), true)
            && !in_array('Disabled row', array_column($informationRows, 'label'), true)
            && !in_array('Failed row', array_column($informationRows, 'label'), true),
        'runtime-failed, disabled, and bootstrap-failed contributions are omitted while healthy plugins continue rendering',
    );

    $readResponse = $registry->dispatch(new Request('GET', '/api/plugins/alpha-enabled/status'), ['id' => 42]);
    $readPayload = $readResponse === null ? null : json_decode($readResponse->body(), true);
    verifyPluginLifecycle(
        $readResponse?->status() === 200
            && $readPayload === ['plugin' => 'alpha-enabled', 'method' => 'GET', 'user_id' => 42],
        'an exact plugin route receives the request and authenticated user and may return a Response',
    );

    $parameterResponse = $registry->dispatch(
        new Request(
            'GET',
            '/api/plugins/alpha-enabled/items/alpha%2D42',
            ['filter' => 'active'],
            ['x-plugin-probe' => 'present'],
        ),
        ['id' => 42],
    );
    verifyPluginLifecycle(
        json_decode($parameterResponse?->body() ?? '', true) === [
            'item' => 'alpha-42',
            'filter' => 'active',
            'probe' => 'present',
        ],
        'a bounded route parameter is decoded and exposed with query and header input',
    );
    $literalResponse = $registry->dispatch(new Request('GET', '/api/plugins/alpha-enabled/items/new'), ['id' => 42]);
    verifyPluginLifecycle(
        json_decode($literalResponse?->body() ?? '', true) === ['item' => 'literal'],
        'a literal plugin route takes precedence over an earlier parameterized route',
    );
    $jsonResponse = $registry->dispatch(
        new Request('POST', '/api/plugins/alpha-enabled/items/alpha-42', [], [], '{"enabled":true}'),
        ['id' => 42],
    );
    verifyPluginLifecycle(
        json_decode($jsonResponse?->body() ?? '', true) === ['item' => 'alpha-42', 'body' => ['enabled' => true]],
        'a parameterized mutation receives a safely decoded JSON object',
    );
    verifyPluginLifecycle(
        $registry->dispatch(new Request('GET', '/api/plugins/alpha-enabled/items/a%2Fb'), ['id' => 42]) === null
            && $registry->dispatch(new Request('GET', '/api/plugins/alpha-enabled/items/' . str_repeat('a', 129)), ['id' => 42]) === null,
        'plugin route parameters reject encoded separators and values beyond the segment bound',
    );

    $mutationResponse = $registry->dispatch(new Request('POST', '/api/plugins/alpha-enabled/action'), ['id' => 42]);
    verifyPluginLifecycle(
        $mutationResponse?->status() === 200
            && json_decode($mutationResponse->body(), true) === ['ok' => true, 'user_id' => 42],
        'an array returned by a plugin route is normalized to a JSON response',
    );
    verifyPluginLifecycle(
        $registry->dispatch(new Request('GET', '/api/plugins/alpha-enabled/missing'), ['id' => 42]) === null,
        'plugin route dispatch uses an exact method and path match',
    );
    verifyPluginLifecycle(
        !isset($plugins['eta-foreign-route'])
            && !isset($plugins['iota-duplicate-route'])
            && $registry->dispatch(new Request('GET', '/api/plugins/eta-foreign-route/status'), ['id' => 42]) === null
            && $registry->dispatch(new Request('GET', '/api/plugins/iota-duplicate-route/status'), ['id' => 42]) === null,
        'namespace and duplicate-route violations atomically discard the offending plugins',
    );

    verifyPluginLifecycle(
        pluginRouteRegistrationAccepted('/api/plugins/bounded/literal/{one}/{two}/{three}/{four}/{five}/{six}/tail')
            && !pluginRouteRegistrationAccepted('/api/plugins/bounded/' . implode('/', array_fill(0, 9, 'segment')))
            && !pluginRouteRegistrationAccepted('/api/plugins/bounded/{one}/{two}/{three}/{four}/{five}/{six}/{seven}')
            && !pluginRouteRegistrationAccepted('/api/plugins/bounded/{item}/{item}')
            && !pluginRouteRegistrationAccepted('/api/plugins/bounded/{Invalid}'),
        'plugin route registration accepts its exact bounds and rejects excess depth, excess parameters, duplicate names, and invalid names',
    );

    $publicRegistry = new PublicPluginRegistry();
    $publicRegistration = $publicRegistry->begin('bounded-public', ['/short']);
    $publicRegistry->route('GET', '/short/{slug}', static fn(Request $request): Response => Response::json(['slug' => $request->route('slug')]));
    $publicRegistry->route('GET', '/short/new', static fn(Request $request): Response => Response::json(['slug' => 'literal']));
    $publicMethodRejected = false;
    $publicPrefixRejected = false;
    try { $publicRegistry->route('POST', '/short/action', static fn(Request $request): Response => Response::json([])); }
    catch (\N3\Plugin\PluginRegistrationException) { $publicMethodRejected = true; }
    try { $publicRegistry->route('GET', '/foreign/action', static fn(Request $request): Response => Response::json([])); }
    catch (\N3\Plugin\PluginRegistrationException) { $publicPrefixRejected = true; }
    $publicRegistry->commit($publicRegistration);
    verifyPluginLifecycle(
        $publicMethodRejected
            && $publicPrefixRejected
            && json_decode($publicRegistry->dispatch(new Request('GET', '/short/example'))?->body() ?? '', true) === ['slug' => 'example']
            && json_decode($publicRegistry->dispatch(new Request('GET', '/short/new'))?->body() ?? '', true) === ['slug' => 'literal']
            && $publicRegistry->dispatch(new Request('GET', '/short/a%2Fb')) === null,
        'public registry permits only GET/HEAD under the claimed prefix and preserves bounded matching and literal precedence',
    );

    $declaredAsset = $manager->asset('alpha-enabled', 'plugin.css');
    $declaredScript = $manager->asset('alpha-enabled', 'plugin.js');
    verifyPluginLifecycle(
        $declaredAsset !== null
            && $declaredAsset['mime'] === 'text/css; charset=utf-8'
            && $declaredScript !== null
            && $declaredScript['mime'] === 'text/javascript; charset=utf-8',
        'declared assets from a loaded plugin resolve with their manifest field MIME types',
    );
    verifyPluginLifecycle(
        $manager->asset('alpha-enabled', '../beta-disabled/plugin.css') === null
            && $manager->asset('alpha-enabled', 'notes.txt') === null
            && $manager->asset('alpha-enabled', 'undeclared.js') === null,
        'asset lookup rejects traversal, unsupported files, and undeclared browser assets',
    );

    verifyPluginLifecycle(
        $manager->asset('alpha-enabled', 'wrong-type.js') === null
            && $manager->asset('alpha-enabled', 'wrong-type.css') === null,
        'CSS and JavaScript declarations cannot cross their manifest asset types',
    );
    verifyPluginLifecycle(
        $manager->asset('beta-disabled', 'plugin.css') === null
            && $manager->asset('theta-failing', 'failed.js') === null,
        'disabled and failed plugins cannot serve declared browser assets',
    );

    $partialResponse = $registry->dispatch(new Request('GET', '/api/plugins/theta-failing/partial'), ['id' => 42]);
    verifyPluginLifecycle(
        !isset($plugins['theta-failing']) && $partialResponse === null,
        'a failed bootstrap atomically discards its manifest widget, bootstrap widget, and route',
    );
    $healthyResponse = $registry->dispatch(new Request('GET', '/api/plugins/zeta-healthy/status'), ['id' => 42]);
    verifyPluginLifecycle(
        json_decode($healthyResponse?->body() ?? '', true) === ['healthy' => true],
        'a failed plugin does not prevent a later valid plugin from loading',
    );
    verifyPluginLifecycle(
        is_file($errorLog)
            && str_contains((string)file_get_contents($errorLog), 'Plugin eta-foreign-route failed during bootstrap')
            && str_contains((string)file_get_contents($errorLog), 'Plugin iota-duplicate-route failed during bootstrap')
            && str_contains((string)file_get_contents($errorLog), 'Plugin theta-failing failed during bootstrap')
            && str_contains((string)file_get_contents($errorLog), 'Plugin alpha-enabled contribution failed for slot profile_cards.')
            && !str_contains((string)file_get_contents($errorLog), 'private context'),
        'registration, bootstrap, and runtime contribution failures are logged without contribution exception details',
    );
} finally {
    unset($GLOBALS['n3_plugin_fixture_bootstrap_required']);
    ini_set('error_log', is_string($previousErrorLog) ? $previousErrorLog : '');
    if (is_file($errorLog)) unlink($errorLog);
}

echo "\nn3 plugin lifecycle characterization test passed.\n";
