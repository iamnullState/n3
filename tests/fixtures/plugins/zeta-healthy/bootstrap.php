<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Plugin\PluginRegistry;

return static function (PluginRegistry $registry): void {
    $registry->profileCard(static fn(array $context): array => [
        'title' => 'Healthy card',
        'body' => 'Rendered after another contribution fails.',
        'url' => '/api/plugins/zeta-healthy/status',
    ]);
    $registry->route(
        'GET',
        '/api/plugins/zeta-healthy/status',
        static fn(Request $request, array $user): array => ['healthy' => true],
    );
};
