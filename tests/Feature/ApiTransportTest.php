<?php

declare(strict_types=1);

namespace N3\Tests\Feature;

use N3\Core\Application;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\View\View;
use PHPUnit\Framework\TestCase;

final class ApiTransportTest extends TestCase
{
    private Application $application;

    protected function setUp(): void
    {
        /** @var Application $application */
        $application = require dirname(__DIR__, 2) . '/bootstrap/app.php';
        $this->application = $application;
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    public function testPublicLivenessProbeUsesTheVersionedJsonEnvelope(): void
    {
        $response = $this->application->handle(Request::create('GET', '/api/v1/system/ping'));
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->status());
        self::assertSame(['status' => 'ok'], $body['data']);
        self::assertSame($response->headers()['X-Request-ID'], $body['meta']['request_id']);
        self::assertSame('1', $response->headers()['X-N3-API-Version']);
        self::assertSame('no-store', $response->headers()['Cache-Control']);
        self::assertArrayNotHasKey('database', $body['data']);
        self::assertArrayNotHasKey('modules', $body['data']);
    }

    public function testUnknownApiRoutesUseControlledJsonErrors(): void
    {
        $response = $this->application->handle(Request::create('GET', '/api/v1/missing'));
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->status());
        self::assertSame('not_found', $body['error']['code']);
        self::assertStringNotContainsString('RouteNotFound', $response->body());
        self::assertSame($response->headers()['X-Request-ID'], $body['meta']['request_id']);
    }

    public function testApiMethodErrorsRemainJsonAndDeclareAllowedMethods(): void
    {
        $response = $this->application->handle(Request::create('POST', '/api/v1/system/ping'));
        $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(405, $response->status());
        self::assertSame('GET', $response->headers()['Allow']);
        self::assertSame('method_not_allowed', $body['error']['code']);
    }

    public function testUnexpectedApiFailuresUseControlledJsonWithoutExceptionDetails(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'n3-api-log-');
        self::assertNotFalse($log);
        $router = new Router();
        $router->get('/api/v1/failure', static fn (Request $request): Response => throw new \RuntimeException('private failure detail'));
        $application = new Application(
            $router,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            new FileLogger($log),
            'testing',
        );

        try {
            $response = $application->handle(Request::create('GET', '/api/v1/failure'));
            $body = json_decode($response->body(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(500, $response->status());
            self::assertSame('internal_error', $body['error']['code']);
            self::assertStringNotContainsString('private failure detail', $response->body());
            self::assertStringNotContainsString('RuntimeException', $response->body());
        } finally {
            unlink($log);
        }
    }

    public function testNonApiNotFoundBehaviorRemainsHtml(): void
    {
        $response = $this->application->handle(Request::create('GET', '/still-missing'));

        self::assertSame(404, $response->status());
        self::assertSame('text/html; charset=UTF-8', $response->headers()['Content-Type']);
    }
}
