<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Application;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\View\View;
use PHPUnit\Framework\TestCase;

final class ApplicationSecurityHeadersTest extends TestCase
{
    private string $log;

    protected function setUp(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'n3-security-headers-');
        self::assertNotFalse($log);
        $this->log = $log;
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
    }

    public function testProductionHttpsResponseIncludesHsts(): void
    {
        $response = $this->application('production')->handle(Request::create('GET', '/', server: ['HTTPS' => 'on']));

        self::assertSame('max-age=31536000', $response->headers()['Strict-Transport-Security']);
    }

    public function testHstsIsNotSentForHttpOrForwardedHeaders(): void
    {
        $response = $this->application('production')->handle(Request::create(
            'GET',
            '/',
            server: ['HTTP_X_FORWARDED_PROTO' => 'https'],
        ));

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers());
    }

    public function testHstsIsNotSentOutsideProduction(): void
    {
        $response = $this->application('test')->handle(Request::create('GET', '/', server: ['HTTPS' => 'on']));

        self::assertArrayNotHasKey('Strict-Transport-Security', $response->headers());
    }

    private function application(string $environment): Application
    {
        $router = new Router();
        $router->get('/', static fn (Request $request): Response => Response::html('<h1>Okay</h1>'));

        return new Application(
            $router,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            new FileLogger($this->log),
            $environment,
        );
    }
}
