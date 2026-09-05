<?php

declare(strict_types=1);

namespace N3\Tests\Feature;

use N3\Core\Application;
use N3\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class LandingPageTest extends TestCase
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

    public function testLandingPageUsesTheCoreRequestLifecycle(): void
    {
        $response = $this->application->handle(Request::create('GET', '/'));

        self::assertSame(200, $response->status());
        self::assertStringContainsString('<span class="greeting-word greeting-hello">Hello,</span>', $response->body());
        self::assertStringContainsString('<span class="greeting-word greeting-world">world</span>', $response->body());
        self::assertStringNotContainsString('N3', $response->body());
        self::assertStringNotContainsString('White-label CMS framework', $response->body());
        self::assertStringContainsString('Content-Security-Policy', implode(' ', array_keys($response->headers())));
        self::assertArrayHasKey('X-Request-ID', $response->headers());
    }

    public function testUnknownPageReturnsTheSafeNotFoundView(): void
    {
        $response = $this->application->handle(Request::create('GET', '/missing'));

        self::assertSame(404, $response->status());
        self::assertStringContainsString('Page not found', $response->body());
        self::assertStringNotContainsString('RouteNotFound', $response->body());
    }
}
