<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginContext;
use N3\Plugin\PluginRegistry;

return static function (PluginRegistry $registry, PluginContext $context): void {
    $registry->profileTool(static fn(array $context): array => [
        'label' => 'Open profile report',
        'url' => '/api/plugins/reference-plugin/items/profile?view=' . rawurlencode((string)$context['audience']),
    ]);
    $registry->profileCard(static fn(array $context): array => [
        'title' => 'Profile summary',
        'body' => array_sum($context['profile']['page_counts']) . ' viewer-authorized pages are represented in this profile.',
        'url' => '/api/plugins/reference-plugin/items/profile?view=summary',
    ]);
    $registry->pageInformationRow(static fn(array $context): array => [
        'label' => 'Reference state',
        'value' => $context['page']['can_edit'] ? 'Editable' : 'Read only',
    ]);
    $registry->route(
        'GET',
        '/api/plugins/reference-plugin/items/{item_id}',
        static function (Request $request, array $user): Response {
            $view = $request->query('view', 'summary');
            return Response::json([
                'plugin' => 'reference-plugin',
                'item_id' => $request->route('item_id'),
                'view' => is_string($view) ? $view : 'summary',
                'trace' => $request->header('X-Reference-Trace'),
                'method' => $request->method(),
                'user_id' => (int)$user['id'],
            ]);
        },
    );

    $registry->route(
        'POST',
        '/api/plugins/reference-plugin/items/{item_id}/events',
        static function (Request $request, array $user): array|Response {
            $payload = $request->json();
            if ($payload === null) return Response::json(['error' => 'Expected a JSON object.'], 400);
            return [
                'ok' => true,
                'item_id' => $request->route('item_id'),
                'event' => is_string($payload['event'] ?? null) ? $payload['event'] : 'updated',
                'user_id' => (int)$user['id'],
            ];
        },
    );

    $registry->route(
        'GET',
        '/api/plugins/reference-plugin/accounts/{account_id}',
        static fn(Request $request): array => [
            'account' => $context->account((int)$request->route('account_id', '0')),
        ],
    );

    $registry->route(
        'POST',
        '/api/plugins/reference-plugin/uploads',
        static function (Request $request): array|Response {
            $upload = $request->file('example');
            if ($upload === null) return Response::json(['error' => 'Expected one uploaded file.'], 400);
            return ['name' => $upload['name'], 'size' => $upload['size']];
        },
    );
};
