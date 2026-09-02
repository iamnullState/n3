<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Application;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Observability\RequestMetric;
use N3\Core\Observability\RequestMetricClassifier;
use N3\Core\Observability\RequestMetricsSink;
use N3\Core\View\View;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RequestMetricsTest extends TestCase
{
    public function testClassifierEmitsOnlyControlledCategoriesWithoutRawRequestData(): void
    {
        $classifier = new RequestMetricClassifier();
        $cases = [
            '/' => 'public.home',
            '/pages/private-looking-slug?token=secret' => 'public.page',
            '/media/0123456789abcdef0123456789abcdef.webp?token=secret' => 'public.media',
            '/login?email=person%40example.test' => 'identity',
            '/verify-email?token=private' => 'identity',
            '/admin/pages/184/edit' => 'admin.pages',
            '/admin/analytics?days=90' => 'admin.analytics',
            '/admin/media/0123456789abcdef0123456789abcdef/preview?token=private' => 'admin.media',
            '/api/v1/system/ping' => 'api.system',
            '/api/v1/users/person@example.test' => 'api.other',
            '/unknown/private-value' => 'other',
        ];

        foreach ($cases as $uri => $expected) {
            $category = $classifier->classify(Request::create('GET', $uri));
            self::assertSame($expected, $category);
            self::assertContains($category, RequestMetric::ROUTE_CATEGORIES);
            self::assertStringNotContainsString('secret', $category);
            self::assertStringNotContainsString('example', $category);
        }
    }

    public function testApplicationRecordsTheControlledOutcomeAfterSecuringTheResponse(): void
    {
        $sink = new CollectingRequestMetricsSink();
        [$application, $log] = $this->application($sink);

        try {
            $response = $application->handle(Request::create('GET', '/pages/a-private-slug?token=private'));

            self::assertSame(200, $response->status());
            self::assertCount(1, $sink->metrics);
            self::assertSame('public.page', $sink->metrics[0]->routeCategory);
            self::assertSame('GET', $sink->metrics[0]->method);
            self::assertSame(200, $sink->metrics[0]->statusCode);
            self::assertGreaterThanOrEqual(0, $sink->metrics[0]->durationMicroseconds);
            self::assertObjectNotHasProperty('path', $sink->metrics[0]);
            self::assertObjectNotHasProperty('requestId', $sink->metrics[0]);
        } finally {
            unlink($log);
        }
    }

    public function testMetricsFailureIsSanitizedAndDoesNotChangeTheResponse(): void
    {
        [$application, $log] = $this->application(new ThrowingRequestMetricsSink());

        try {
            $response = $application->handle(Request::create('GET', '/pages/do-not-log-me?token=private'));
            $logged = (string) file_get_contents($log);

            self::assertSame(200, $response->status());
            self::assertStringContainsString('request_metrics_failed', $logged);
            self::assertStringContainsString(RuntimeException::class, $logged);
            self::assertStringNotContainsString('do-not-log-me', $logged);
            self::assertStringNotContainsString('private', $logged);
            self::assertStringNotContainsString('database secret detail', $logged);
        } finally {
            unlink($log);
        }
    }

    /** @return array{Application, string} */
    private function application(RequestMetricsSink $sink): array
    {
        $log = tempnam(sys_get_temp_dir(), 'n3-metrics-log-');
        self::assertNotFalse($log);
        $router = new Router();
        $router->get('/pages/{slug}', static fn (Request $request): Response => Response::html('<h1>Page</h1>'));

        return [new Application(
            $router,
            new View(dirname(__DIR__, 2) . '/resources/views'),
            new FileLogger($log),
            'testing',
            $sink,
        ), $log];
    }
}

final class CollectingRequestMetricsSink implements RequestMetricsSink
{
    /** @var list<RequestMetric> */
    public array $metrics = [];

    public function record(RequestMetric $metric): void
    {
        $this->metrics[] = $metric;
    }
}

final class ThrowingRequestMetricsSink implements RequestMetricsSink
{
    public function record(RequestMetric $metric): void
    {
        throw new RuntimeException('database secret detail');
    }
}
