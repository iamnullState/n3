<?php
declare(strict_types=1);

use N3\Service\AuthService;

function verifyPluginRequest(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function dispatchPluginRequest(
    string $method,
    string $path,
    string $sessionId = '',
    string $csrf = '',
    string $probeHeader = '',
    string $body = '',
): array
{
    putenv('N3_TEST_METHOD=' . $method);
    putenv('N3_TEST_PATH=' . $path);
    putenv('N3_TEST_SESSION_ID=' . $sessionId);
    putenv('N3_TEST_CSRF=' . $csrf);
    putenv('N3_TEST_PROBE_HEADER=' . $probeHeader);
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/fixtures/plugin_request.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) throw new RuntimeException('Could not start the plugin request probe.');
    if ($body !== '') fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'output' => (string)$output, 'errors' => (string)$errors];
}

function clearPluginBootMarker(string $path): void
{
    if (is_file($path)) unlink($path);
}

function removePluginRequestDirectory(string $path): void
{
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $target = $path . '/' . $entry;
        if (is_dir($target) && !is_link($target)) removePluginRequestDirectory($target);
        else unlink($target);
    }
    rmdir($path);
}

$temp = sys_get_temp_dir() . '/n3-plugin-requests-' . bin2hex(random_bytes(5));
$sessionPath = $temp . '/sessions';
$mediaPath = $temp . '/media';
$marker = $temp . '/plugin-boot.log';
$publicMarker = $temp . '/plugin-public.log';
$errorLog = $temp . '/application-errors.log';
mkdir($sessionPath, 0700, true);
mkdir($mediaPath, 0700, true);
$mediaFilename = str_repeat('a', 40) . '.jpg';
file_put_contents($mediaPath . '/' . $mediaFilename, 'public media probe');

putenv('DATA_DIR=' . $temp);
putenv('N3_PLUGIN_DIR=' . __DIR__ . '/fixtures/request-plugins');
putenv('N3_PLUGIN_BOOT_MARKER=' . $marker);
putenv('N3_PLUGIN_PUBLIC_MARKER=' . $publicMarker);
putenv('N3_TEST_SESSION_PATH=' . $sessionPath);
putenv('N3_TEST_ERROR_LOG=' . $errorLog);
ini_set('session.save_path', $sessionPath);

require dirname(__DIR__) . '/src/bootstrap.php';
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
});

$bootstrapWasNonExecutable = !is_file($marker);
$setupBeforeOwner = dispatchPluginRequest('GET', '/setup');
$setupBeforeOwnerWasIndependent = $setupBeforeOwner['exit'] === 0
    && str_contains($setupBeforeOwner['output'], 'Create your account')
    && !is_file($marker);
$userId = (new AuthService(db()))->createOwner('plugin-owner', 'correct horse battery staple');
if ($userId === null) throw new RuntimeException('Could not create the plugin lifecycle owner.');
$collaboratorId = (new AuthService(db()))->createCollaborator('plugin-reader', 'correct horse battery staple');
$ownerProfileSlug = (string)db()->query('SELECT profile_slug FROM users WHERE id = ' . (int)$userId)->fetchColumn();
$ownerPageId = (int)db()->query('SELECT id FROM pages WHERE author_id = ' . (int)$userId . " AND kind = 'page' ORDER BY id LIMIT 1")->fetchColumn();

$sessionId = 'pluginrequest' . bin2hex(random_bytes(8));
$csrf = bin2hex(random_bytes(32));
session_id($sessionId);
startSecureSession();
$sessionId = session_id();
$_SESSION['user_id'] = $userId;
$_SESSION['session_version'] = 1;
$_SESSION['csrf'] = $csrf;
session_write_close();

$collaboratorSessionId = 'plugincollaborator' . bin2hex(random_bytes(8));
$collaboratorCsrf = bin2hex(random_bytes(32));
session_id($collaboratorSessionId);
startSecureSession();
$collaboratorSessionId = session_id();
$_SESSION['user_id'] = $collaboratorId;
$_SESSION['session_version'] = 1;
$_SESSION['csrf'] = $collaboratorCsrf;
session_write_close();

try {
    verifyPluginRequest($bootstrapWasNonExecutable, 'global application bootstrap discovers manifests without executing plugin PHP');
    verifyPluginRequest($setupBeforeOwnerWasIndependent, 'initial account setup renders without executing plugin PHP');

    $publicRequests = [
        ['GET', '/api/health', ''],
        ['GET', '/api/health', $sessionId],
        ['GET', '/', ''],
        ['GET', '/public', $sessionId],
        ['GET', '/tags', $sessionId],
        ['GET', '/sitemap.xml', $sessionId],
        ['GET', '/feed.xml', $sessionId],
        ['GET', '/p/not-a-published-page', $sessionId],
        ['GET', '/u/not-a-profile', ''],
        ['GET', '/media/' . $mediaFilename, $sessionId],
        ['GET', '/avatar/not-a-profile', ''],
        ['GET', '/setup', ''],
        ['GET', '/login', ''],
        ['GET', '/login', $sessionId],
        ['GET', '/api/bootstrap', ''],
        ['GET', '/api/profile', $sessionId],
        ['GET', '/dashboard', ''],
        ['GET', '/plugin-assets/request-probe/probe.js', ''],
        ['GET', '/plugin-media/request-probe/' . $mediaFilename, ''],
    ];
    foreach ($publicRequests as [$method, $path, $requestSession]) {
        clearPluginBootMarker($marker);
        $response = dispatchPluginRequest($method, $path, $requestSession);
        verifyPluginRequest(
            $response['exit'] === 0 && !is_file($marker),
            $path . ($requestSession !== '' ? ' with a session' : '') . ' completes without executing plugin PHP',
        );
    }

    clearPluginBootMarker($marker);
    clearPluginBootMarker($publicMarker);
    $declaredPublic = dispatchPluginRequest('GET', '/request-public/example');
    verifyPluginRequest(
        $declaredPublic['exit'] === 0
            && json_decode($declaredPublic['output'], true) === ['public' => true, 'slug' => 'example', 'plugin' => 'public-probe']
            && is_file($publicMarker)
            && !is_file($marker),
        'a claimed anonymous prefix executes only the separate public hook before authentication',
    );
    $disablePublic = dispatchPluginRequest(
        'PUT',
        '/api/plugins/public-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":false}',
    );
    clearPluginBootMarker($marker);
    clearPluginBootMarker($publicMarker);
    $disabledPublic = dispatchPluginRequest('GET', '/request-public/example');
    verifyPluginRequest(
        (json_decode($disablePublic['output'], true)['plugin']['effective_enabled'] ?? null) === false
            && $disabledPublic['output'] === 'Link not found.'
            && !is_file($publicMarker)
            && !is_file($marker),
        'a stored disable override makes the claimed public prefix fail closed without executing plugin PHP',
    );
    dispatchPluginRequest('PUT', '/api/plugins/public-probe', $sessionId, $csrf, '', '{"enabled":true}');

    db()->exec("UPDATE users SET profile_visibility = 'public' WHERE id = " . (int)$userId);
    clearPluginBootMarker($marker);
    $anonymousPublicProfile = dispatchPluginRequest('GET', '/u/' . $ownerProfileSlug);
    verifyPluginRequest(
        str_contains($anonymousPublicProfile['output'], 'plugin-owner')
            && !str_contains($anonymousPublicProfile['output'], 'Request profile card')
            && !is_file($marker),
        'anonymous public profiles omit authenticated plugin output without executing plugin PHP',
    );

    clearPluginBootMarker($marker);
    $authenticatedProfile = dispatchPluginRequest('GET', '/u/' . $ownerProfileSlug, $sessionId);
    verifyPluginRequest(
        str_contains($authenticatedProfile['output'], 'Request profile card') && is_file($marker),
        'signed-in profile surfaces boot plugins and render declared structured profile contributions',
    );

    clearPluginBootMarker($marker);
    $anonymousDiagnostics = dispatchPluginRequest('GET', '/api/diagnostics');
    verifyPluginRequest(
        str_contains($anonymousDiagnostics['output'], 'Authentication required') && !is_file($marker),
        'anonymous system diagnostics are rejected before plugin boot',
    );

    clearPluginBootMarker($marker);
    $collaboratorDiagnostics = dispatchPluginRequest('GET', '/api/diagnostics', $collaboratorSessionId);
    verifyPluginRequest(
        str_contains($collaboratorDiagnostics['output'], 'Administrator access is required') && !is_file($marker),
        'non-administrators cannot read system diagnostics',
    );

    clearPluginBootMarker($marker);
    $administratorDiagnostics = dispatchPluginRequest('GET', '/api/diagnostics', $sessionId);
    $diagnosticsPayload = json_decode($administratorDiagnostics['output'], true)['diagnostics'] ?? [];
    verifyPluginRequest(
        ($diagnosticsPayload['version'] ?? null) === \N3\Support\Version::current()
            && ($diagnosticsPayload['storage']['data_writable'] ?? null) === true
            && ($diagnosticsPayload['database'] ?? null) === ['status' => 'ok', 'integrity' => 'ok', 'foreign_keys' => 'ok', 'schema_version' => 5]
            && ($diagnosticsPayload['backup']['status'] ?? null) === 'missing'
            && !str_contains($administratorDiagnostics['output'], $temp)
            && !is_file($marker),
        'administrators receive sanitized system diagnostics without executing plugin PHP',
    );

    clearPluginBootMarker($marker);
    $anonymousInventory = dispatchPluginRequest('GET', '/api/plugins');
    verifyPluginRequest(
        str_contains($anonymousInventory['output'], 'Authentication required') && !is_file($marker),
        'anonymous plugin inventory is rejected before plugin boot',
    );

    clearPluginBootMarker($marker);
    $collaboratorInventory = dispatchPluginRequest('GET', '/api/plugins', $collaboratorSessionId);
    verifyPluginRequest(
        str_contains($collaboratorInventory['output'], 'Administrator access is required') && !is_file($marker),
        'non-administrators cannot read plugin inventory or diagnostics',
    );

    clearPluginBootMarker($marker);
    $collaboratorUpdate = dispatchPluginRequest(
        'PUT',
        '/api/plugins/request-probe',
        $collaboratorSessionId,
        $collaboratorCsrf,
        '',
        '{"enabled":false}',
    );
    verifyPluginRequest(
        str_contains($collaboratorUpdate['output'], 'Administrator access is required') && !is_file($marker),
        'non-administrators cannot change plugin enablement',
    );

    clearPluginBootMarker($marker);
    $inventory = dispatchPluginRequest('GET', '/api/plugins', $sessionId);
    $inventoryPayload = json_decode($inventory['output'], true);
    $inventoryById = array_column($inventoryPayload['plugins'] ?? [], null, 'id');
    verifyPluginRequest(
        array_keys($inventoryById) === ['disabled-probe', 'invalid-schema-probe', 'public-probe', 'request-probe', 'throwing-probe']
            && ($inventoryById['request-probe']['status'] ?? null) === 'loaded'
            && ($inventoryById['request-probe']['manifest_enabled'] ?? null) === true
            && array_key_exists('override_enabled', $inventoryById['request-probe'] ?? [])
            && $inventoryById['request-probe']['override_enabled'] === null
            && ($inventoryById['disabled-probe']['status'] ?? null) === 'disabled'
            && ($inventoryById['invalid-schema-probe']['status'] ?? null) === 'invalid'
            && ($inventoryById['invalid-schema-probe']['diagnostic'] ?? null) === 'Plugin manifest field "enabled" must be boolean.'
            && ($inventoryById['throwing-probe']['status'] ?? null) === 'failed'
            && ($inventoryById['throwing-probe']['diagnostic'] ?? null) === 'Plugin bootstrap failed. Check the application log.'
            && ($inventoryById['public-probe']['capabilities']['public_routes'] ?? null) === true
            && ($inventoryById['request-probe']['capabilities'] ?? null) === [
                'php_bootstrap' => true,
                'public_routes' => false,
                'migrations' => 0,
                'dashboard_widgets' => 1,
                'navigation_items' => 0,
                'css_assets' => 1,
                'js_assets' => 1,
                'profile_tools' => false,
                'profile_cards' => true,
                'page_information' => true,
            ]
            && is_file($marker),
        'administrators receive ordered stable state, capability, status, and sanitized diagnostic fields',
    );

    clearPluginBootMarker($marker);
    $rejectedAdminMutation = dispatchPluginRequest(
        'PUT',
        '/api/plugins/request-probe',
        $sessionId,
        '',
        '',
        '{"enabled":false}',
    );
    verifyPluginRequest(
        str_contains($rejectedAdminMutation['output'], 'Your session expired') && !is_file($marker),
        'plugin administration mutations require a valid CSRF token before plugin boot',
    );

    clearPluginBootMarker($marker);
    $invalidAdminMutation = dispatchPluginRequest(
        'PUT',
        '/api/plugins/request-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":"false"}',
    );
    verifyPluginRequest(
        str_contains($invalidAdminMutation['output'], 'The enabled field must be boolean') && !is_file($marker),
        'plugin enablement rejects non-boolean state without executing plugin PHP',
    );

    clearPluginBootMarker($marker);
    $malformedAdminMutation = dispatchPluginRequest(
        'PUT',
        '/api/plugins/request-probe',
        $sessionId,
        $csrf,
        '',
        '{invalid',
    );
    verifyPluginRequest(
        str_contains($malformedAdminMutation['output'], 'Invalid JSON body') && !is_file($marker),
        'plugin enablement rejects malformed JSON without executing plugin PHP',
    );

    clearPluginBootMarker($marker);
    $missingAdminMutation = dispatchPluginRequest(
        'PUT',
        '/api/plugins/not-installed',
        $sessionId,
        $csrf,
        '',
        '{"enabled":false}',
    );
    verifyPluginRequest(
        str_contains($missingAdminMutation['output'], 'Plugin not found') && !is_file($marker),
        'plugin enablement rejects unknown plugin IDs without creating orphan overrides',
    );

    clearPluginBootMarker($marker);
    $invalidManifestMutation = dispatchPluginRequest(
        'PUT',
        '/api/plugins/invalid-schema-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":true}',
    );
    verifyPluginRequest(
        str_contains($invalidManifestMutation['output'], 'Fix the plugin manifest') && !is_file($marker),
        'plugin enablement cannot override an invalid manifest or execute plugin PHP',
    );

    clearPluginBootMarker($marker);
    $disablePlugin = dispatchPluginRequest(
        'PUT',
        '/api/plugins/request-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":false}',
    );
    $disablePayload = json_decode($disablePlugin['output'], true);
    verifyPluginRequest(
        ($disablePayload['plugin']['effective_enabled'] ?? null) === false
            && ($disablePayload['plugin']['status'] ?? null) === 'disabled'
            && ($disablePayload['reload_required'] ?? null) === true
            && !is_file($marker),
        'an administrator can persist a disable override without booting the target plugin',
    );

    clearPluginBootMarker($marker);
    $disabledInventory = dispatchPluginRequest('GET', '/api/plugins', $sessionId);
    $disabledPayload = json_decode($disabledInventory['output'], true);
    $disabledById = array_column($disabledPayload['plugins'] ?? [], null, 'id');
    $disabledBoots = is_file($marker) ? file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    verifyPluginRequest(
        ($disabledById['request-probe']['override_enabled'] ?? null) === false
            && ($disabledById['request-probe']['effective_enabled'] ?? null) === false
            && ($disabledById['request-probe']['status'] ?? null) === 'disabled'
            && $disabledBoots === ['throwing-probe'],
        'the persisted override prevents disabled plugin PHP from running on the next request',
    );

    clearPluginBootMarker($marker);
    $enablePlugin = dispatchPluginRequest(
        'PUT',
        '/api/plugins/request-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":true}',
    );
    verifyPluginRequest(
        (json_decode($enablePlugin['output'], true)['plugin']['effective_enabled'] ?? null) === true
            && !is_file($marker),
        'an administrator can persist an enable override without booting plugin PHP',
    );

    clearPluginBootMarker($marker);
    $enableManifestDisabled = dispatchPluginRequest(
        'PUT',
        '/api/plugins/disabled-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":true}',
    );
    verifyPluginRequest(
        (json_decode($enableManifestDisabled['output'], true)['plugin']['effective_enabled'] ?? null) === true
            && !is_file($marker),
        'a database override can enable a plugin whose read-only manifest defaults to disabled',
    );

    clearPluginBootMarker($marker);
    $enabledManifestAsset = dispatchPluginRequest('GET', '/plugin-assets/disabled-probe/disabled.js', $sessionId);
    verifyPluginRequest(
        str_contains($enabledManifestAsset['output'], 'n3DisabledRequestProbe') && is_file($marker),
        'a manifest-disabled plugin serves its declared asset after its persistent enable override takes effect',
    );

    clearPluginBootMarker($marker);
    $restoreManifestDisabled = dispatchPluginRequest(
        'PUT',
        '/api/plugins/disabled-probe',
        $sessionId,
        $csrf,
        '',
        '{"enabled":false}',
    );
    verifyPluginRequest(
        (json_decode($restoreManifestDisabled['output'], true)['plugin']['effective_enabled'] ?? null) === false
            && !is_file($marker),
        'a later persistent disable returns the manifest-disabled plugin to an inactive effective state',
    );

    clearPluginBootMarker($marker);
    $rejectedMutation = dispatchPluginRequest('POST', '/api/plugins/request-probe/action', $sessionId);
    verifyPluginRequest(
        $rejectedMutation['exit'] === 0
            && str_contains($rejectedMutation['output'], 'Your session expired')
            && !is_file($marker),
        'an authenticated mutation with an invalid CSRF token is rejected before plugin boot',
    );

    clearPluginBootMarker($marker);
    $pluginMutation = dispatchPluginRequest('POST', '/api/plugins/request-probe/action', $sessionId, $csrf);
    $bootedPlugins = is_file($marker) ? file($marker, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    verifyPluginRequest(
        $pluginMutation['exit'] === 0
            && json_decode($pluginMutation['output'], true) === ['ok' => true, 'user_id' => $userId]
            && $bootedPlugins === ['request-probe', 'throwing-probe'],
        'an authenticated CSRF-valid plugin request boots plugins and survives a later plugin failure',
    );

    clearPluginBootMarker($marker);
    $parameterized = dispatchPluginRequest(
        'GET',
        '/api/plugins/request-probe/items/alpha-42?filter=active',
        $sessionId,
        '',
        'request-header',
    );
    verifyPluginRequest(
        $parameterized['exit'] === 0
            && json_decode($parameterized['output'], true) === [
                'item' => 'alpha-42',
                'filter' => 'active',
                'probe' => 'request-header',
                'user_id' => $userId,
            ]
            && is_file($marker),
        'an authenticated parameterized route receives normalized route, query, and header values',
    );

    clearPluginBootMarker($marker);
    $dashboard = dispatchPluginRequest('GET', '/dashboard', $sessionId);
    verifyPluginRequest(
        $dashboard['exit'] === 0
            && str_contains($dashboard['output'], '<title>n3</title>')
            && is_file($marker),
        'an authenticated private application surface boots plugins',
    );

    clearPluginBootMarker($marker);
    $pageDetail = dispatchPluginRequest('GET', '/api/pages/' . $ownerPageId, $sessionId);
    $pageDetailPayload = json_decode($pageDetail['output'], true);
    verifyPluginRequest(
        ($pageDetailPayload['page_information']['plugin_rows'][0]['label'] ?? null) === 'Request state'
            && ($pageDetailPayload['page_information']['plugin_rows'][0]['value'] ?? null) === 'Editable'
            && is_file($marker),
        'authenticated page details include declared page-information rows after core authorization',
    );

    clearPluginBootMarker($marker);
    $bootstrap = dispatchPluginRequest('GET', '/api/bootstrap', $sessionId);
    $bootstrapPayload = json_decode($bootstrap['output'], true);
    verifyPluginRequest(
        $bootstrap['exit'] === 0
            && array_column($bootstrapPayload['plugins'] ?? [], 'id') === ['public-probe', 'request-probe']
            && is_file($marker),
        'the authenticated application bootstrap omits contributions from a failed plugin',
    );

    foreach ([
        ['/plugin-assets/request-probe/probe.css', '.request-probe'],
        ['/plugin-assets/request-probe/probe.js', 'n3RequestProbe'],
    ] as [$assetPath, $needle]) {
        clearPluginBootMarker($marker);
        $assetResponse = dispatchPluginRequest('GET', $assetPath, $sessionId);
        verifyPluginRequest(
            $assetResponse['exit'] === 0
                && str_contains($assetResponse['output'], $needle)
                && is_file($marker),
            $assetPath . ' serves a declared asset from a loaded plugin',
        );
    }

    foreach ([
        '/plugin-assets/request-probe/undeclared.js',
        '/plugin-assets/request-probe/wrong-type.js',
        '/plugin-assets/request-probe/wrong-type.css',
        '/plugin-assets/disabled-probe/disabled.js',
        '/plugin-assets/throwing-probe/failed.js',
        '/plugin-assets/request-probe/%2E%2E%2Fdisabled-probe%2Fdisabled.js',
        '/plugin-assets/request-probe/%2E%2E%5Cdisabled-probe%5Cdisabled.js',
        '/plugin-assets/request-probe/%252E%252E%252Fdisabled-probe%252Fdisabled.js',
    ] as $assetPath) {
        clearPluginBootMarker($marker);
        $assetResponse = dispatchPluginRequest('GET', $assetPath, $sessionId);
        verifyPluginRequest(
            $assetResponse['exit'] === 0
                && $assetResponse['output'] === ''
                && is_file($marker),
            $assetPath . ' is denied after authenticated plugin boot',
        );
    }
} finally {
    clearPluginBootMarker($marker);
    clearPluginBootMarker($publicMarker);
    removePluginRequestDirectory($temp);
}

echo "\nn3 plugin request lifecycle test passed.\n";
