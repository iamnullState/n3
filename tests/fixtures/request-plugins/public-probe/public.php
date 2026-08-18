<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginContext;
use N3\Plugin\PublicPluginRegistry;

$marker = (string)getenv('N3_PLUGIN_PUBLIC_MARKER');
if ($marker !== '') file_put_contents($marker, "public-probe\n", FILE_APPEND);

return static function (PublicPluginRegistry $registry, PluginContext $context): void {
    $registry->route('GET', '/request-public/{slug}', static fn(Request $request): Response => Response::json([
        'public' => true,
        'slug' => $request->route('slug'),
        'plugin' => $context->pluginId(),
    ]));
    $registry->route('HEAD', '/request-public/{slug}', static fn(Request $request): Response => new Response('', 200, ['Cache-Control' => 'no-store']));
};
