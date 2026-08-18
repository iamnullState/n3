<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Application;
use N3\Http\JsonBody;
use N3\Http\Request;
use N3\Http\Response;
use N3\Http\Router;

function verifyHttp(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

verifyHttp(class_exists(Application::class), 'autoload resolves the application dispatcher');
$request = new Request('GET', '/api/pages/42/revisions/7');
$router = new Router($request);
verifyHttp($request->method() === 'GET' && $request->path() === '/api/pages/42/revisions/7', 'request exposes normalized method and path');
verifyHttp($request->isMutation() === false && (new Request('PATCH', '/api/pages/42'))->isMutation(), 'request identifies mutation methods');
$pluginRequest = new Request(
    'POST',
    '/api/plugins/example/items/alpha-42',
    ['filter' => 'active', 'tag' => ['one', 'two']],
    ['X-Plugin-Probe' => 'present', 'CONTENT-TYPE' => 'application/json'],
    '{"enabled":true,"options":{"limit":3}}',
);
$pluginRequest = $pluginRequest->withRouteParams(['item_id' => 'alpha-42']);
verifyHttp(
    $pluginRequest->query('filter') === 'active'
        && $pluginRequest->query('tag') === ['one', 'two']
        && $pluginRequest->query('missing', 'fallback') === 'fallback'
        && $pluginRequest->queryParams() === ['filter' => 'active', 'tag' => ['one', 'two']],
    'request exposes captured query values without reading PHP globals',
);
verifyHttp(
    $pluginRequest->header('x-plugin-probe') === 'present'
        && $pluginRequest->header('Content-Type') === 'application/json'
        && $pluginRequest->header('missing', 'fallback') === 'fallback',
    'request exposes case-insensitive captured headers',
);
verifyHttp(
    $pluginRequest->json() === ['enabled' => true, 'options' => ['limit' => 3]]
        && (new Request('POST', '/', [], [], ''))->json() === []
        && (new Request('POST', '/', [], [], '[]'))->json() === null
        && (new Request('POST', '/', [], [], '{invalid'))->json() === null,
    'request safely decodes JSON objects and rejects invalid or non-object bodies',
);
verifyHttp(JsonBody::decode($pluginRequest) === ['enabled' => true, 'options' => ['limit' => 3]], 'JSON body adapter returns the request object payload');
verifyHttp(
    $pluginRequest->route('item_id') === 'alpha-42'
        && $pluginRequest->route('missing', 'fallback') === 'fallback'
        && $pluginRequest->routeParams() === ['item_id' => 'alpha-42'],
    'request exposes dispatcher-provided route parameters',
);
verifyHttp($router->matches('GET', '/api/pages/{id}/revisions/{revision}') === ['id' => 42, 'revision' => 7], 'router extracts typed numeric parameters');
verifyHttp($router->matches('POST', '/api/pages/{id}/revisions/{revision}') === null, 'router enforces the HTTP method');
verifyHttp((new Router(new Request('GET', '/p/a-stable-slug')))->matches('GET', '/p/{slug}') === ['slug' => 'a-stable-slug'], 'router validates public slugs');
verifyHttp((new Router(new Request('GET', '/media/' . str_repeat('a', 40) . '.mp4')))->matches('ANY', '/media/{filename}') === ['filename' => str_repeat('a', 40) . '.mp4'], 'router captures immutable media filenames');
verifyHttp((new Router(new Request('GET', '/avatar/profile-owner-1')))->matches('ANY', '/avatar/{slug}') === ['slug' => 'profile-owner-1'], 'router captures stable profile slugs for avatar delivery');
verifyHttp((new Router(new Request('GET', '/u/profile-owner-1')))->matches('GET', '/u/{slug}') === ['slug' => 'profile-owner-1'], 'router captures stable profile slugs for profile pages');
verifyHttp((new Router(new Request('DELETE', '/api/health')))->matches('ANY', '/api/health') === [], 'router preserves method-independent routes');

$json = Response::json(['ok' => true], 201);
verifyHttp($json->status() === 201 && $json->headers()['Content-Type'] === 'application/json; charset=utf-8' && $json->body() === '{"ok":true}', 'response builds the existing JSON contract');
$redirect = Response::redirect('/login');
verifyHttp($redirect->status() === 303 && $redirect->headers()['Location'] === '/login', 'response builds the existing redirect contract');

echo "\nn3 HTTP abstraction smoke test passed.\n";
