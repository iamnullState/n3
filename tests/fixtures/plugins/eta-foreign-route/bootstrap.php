<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Plugin\PluginRegistry;

return static function (PluginRegistry $registry): void {
    $registry->route(
        'GET',
        '/api/plugins/eta-foreign-route/status',
        static fn(Request $request, array $user): array => ['own_route' => true],
    );
    $registry->route(
        'GET',
        '/api/plugins/alpha-enabled/status',
        static fn(Request $request, array $user): array => ['hijacked' => true],
    );
};
