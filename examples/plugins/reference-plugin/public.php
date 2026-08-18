<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginContext;
use N3\Plugin\PublicPluginRegistry;

return static function (PublicPluginRegistry $registry, PluginContext $context): void {
    foreach (['GET', 'HEAD'] as $method) {
        $registry->route(
            $method,
            '/reference-plugin-public/status',
            static fn(Request $request): Response => new Response(
                $request->method() === 'HEAD' ? '' : json_encode(['plugin' => $context->pluginId(), 'public' => true], JSON_THROW_ON_ERROR),
                200,
                ['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store'],
            ),
        );
    }
};
