<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Plugin\PluginRegistry;

$marker = (string)getenv('N3_PLUGIN_BOOT_MARKER');
if ($marker !== '') file_put_contents($marker, "request-probe\n", FILE_APPEND | LOCK_EX);

return static function (PluginRegistry $registry): void {
    $registry->profileCard(static fn(array $context): array => [
        'title' => 'Request profile card',
        'body' => 'Visible only on an authenticated profile response.',
        'url' => '/api/plugins/request-probe/profile',
    ]);
    $registry->pageInformationRow(static fn(array $context): array => [
        'label' => 'Request state',
        'value' => $context['page']['can_edit'] ? 'Editable' : 'Read only',
    ]);
    $registry->route(
        'POST',
        '/api/plugins/request-probe/action',
        static fn(Request $request, array $user): array => [
            'ok' => true,
            'user_id' => (int)$user['id'],
        ],
    );
    $registry->route(
        'GET',
        '/api/plugins/request-probe/items/{item_id}',
        static fn(Request $request, array $user): array => [
            'item' => $request->route('item_id'),
            'filter' => $request->query('filter'),
            'probe' => $request->header('X-Plugin-Probe'),
            'user_id' => (int)$user['id'],
        ],
    );
};
