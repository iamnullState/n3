<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginRegistry;

$GLOBALS['n3_plugin_fixture_bootstrap_required'][] = 'alpha-enabled';

return static function (PluginRegistry $registry): void {
    $registry->profileTool(static fn(array $context): array => [
        'label' => "Inspect\nprofile",
        'url' => '/api/plugins/alpha-enabled/profile',
    ]);
    $registry->profileCard(static function (array $context): array {
        throw new RuntimeException('expected contribution failure containing private context');
    });
    $registry->pageInformationRow(static fn(array $context): array => [
        'label' => 'Review state',
        'value' => !empty($context['page']['can_edit']) ? 'Editable' : 'Read only',
    ]);
    $registry->dashboardWidget([
        'title' => 'Bootstrap card',
        'body' => 'Registered from bootstrap.php.',
        'url' => '/api/plugins/alpha-enabled/status',
    ]);
    $registry->route(
        'GET',
        '/api/plugins/alpha-enabled/status',
        static fn(Request $request, array $user): Response => Response::json([
            'plugin' => 'alpha-enabled',
            'method' => $request->method(),
            'user_id' => (int)$user['id'],
        ]),
    );
    $registry->route(
        'POST',
        '/api/plugins/alpha-enabled/action',
        static fn(Request $request, array $user): array => [
            'ok' => true,
            'user_id' => (int)$user['id'],
        ],
    );
    $registry->route(
        'GET',
        '/api/plugins/alpha-enabled/items/{item_id}',
        static fn(Request $request, array $user): array => [
            'item' => $request->route('item_id'),
            'filter' => $request->query('filter'),
            'probe' => $request->header('X-Plugin-Probe'),
        ],
    );
    $registry->route(
        'GET',
        '/api/plugins/alpha-enabled/items/new',
        static fn(Request $request, array $user): array => ['item' => 'literal'],
    );
    $registry->route(
        'POST',
        '/api/plugins/alpha-enabled/items/{item_id}',
        static fn(Request $request, array $user): array => [
            'item' => $request->route('item_id'),
            'body' => $request->json(),
        ],
    );
};
