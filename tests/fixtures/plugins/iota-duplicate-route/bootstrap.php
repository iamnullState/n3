<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Plugin\PluginRegistry;

return static function (PluginRegistry $registry): void {
    $registry->route(
        'GET',
        '/api/plugins/iota-duplicate-route/items/{item_id}',
        static fn(Request $request, array $user): array => ['registration' => 'first'],
    );
    $registry->route(
        'GET',
        '/api/plugins/iota-duplicate-route/items/{slug}',
        static fn(Request $request, array $user): array => ['registration' => 'second'],
    );
};
