<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Http\Request;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CurrentPrincipal;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Security\LazyCurrentPrincipalProvider;
use N3\Core\View\View;
use N3\Module\Analytics\AnalyticsController;
use N3\Module\Analytics\AnalyticsReport;
use N3\Module\Analytics\AnalyticsReportReader;
use N3\Module\Analytics\AnalyticsRouteReport;
use N3\Module\Analytics\AnalyticsVitals;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnalyticsDashboardTest extends TestCase
{
    public function testAnonymousAndMemberAccessNeverReadAnalytics(): void
    {
        $reader = new DashboardReportReader($this->report());
        [$anonymous, $anonymousLog] = $this->controller(null, $reader);
        [$member, $memberLog] = $this->controller(new CurrentPrincipal('member'), $reader);

        try {
            $redirect = $anonymous->index(Request::create('GET', '/admin/analytics'));
            self::assertSame(303, $redirect->status());
            self::assertSame('/login', $redirect->headers()['Location']);
            self::assertSame('no-store', $redirect->headers()['Cache-Control']);

            $forbidden = $member->index(Request::create('GET', '/admin/analytics'));
            self::assertSame(403, $forbidden->status());
            self::assertStringContainsString('Access denied', $forbidden->body());
            self::assertSame(0, $reader->calls);
        } finally {
            unlink($anonymousLog);
            unlink($memberLog);
        }
    }

    public function testAdministratorReceivesBoundedAccessibleAggregateReport(): void
    {
        $reader = new DashboardReportReader($this->report());
        [$controller, $log] = $this->controller(new CurrentPrincipal('admin'), $reader);

        try {
            $response = $controller->index(Request::create('GET', '/admin/analytics?days=30&token=private'));

            self::assertSame(200, $response->status());
            self::assertSame('no-store', $response->headers()['Cache-Control']);
            self::assertSame('noindex, nofollow', $response->headers()['X-Robots-Tag']);
            self::assertSame(1, $reader->calls);
            self::assertSame('2026-08-02 12:00:00', $reader->since?->format('Y-m-d H:i:s'));
            self::assertSame('2026-09-01 12:00:00', $reader->until?->format('Y-m-d H:i:s'));
            self::assertStringContainsString('<table class="analytics-table">', $response->body());
            self::assertStringContainsString('<caption>', $response->body());
            self::assertStringContainsString('scope="row"', $response->body());
            self::assertStringContainsString('aria-current="page"', $response->body());
            self::assertStringContainsString('public.page', $response->body());
            self::assertStringContainsString('No visitor-level tracking', $response->body());
            self::assertSame(6, substr_count($response->body(), 'class="vital-card vital-pass"'));
            self::assertStringNotContainsString('class="vital-card vital-attention"', $response->body());
            self::assertStringNotContainsString('private', $response->body());
            self::assertLessThanOrEqual(32 * 1024, strlen($response->body()));
        } finally {
            unlink($log);
        }
    }

    public function testInvalidPeriodIsRejectedBeforeReporting(): void
    {
        $reader = new DashboardReportReader($this->report());
        [$controller, $log] = $this->controller(new CurrentPrincipal('admin'), $reader);

        try {
            $response = $controller->index(Request::create('GET', '/admin/analytics?days=365'));
            self::assertSame(400, $response->status());
            self::assertStringContainsString('1, 7, 30, or 90 days', $response->body());
            self::assertSame(400, $controller->index(Request::create('GET', '/admin/analytics?days=07'))->status());
            self::assertSame(400, $controller->index(Request::create('GET', '/admin/analytics?days[]=7'))->status());
            self::assertSame(0, $reader->calls);
        } finally {
            unlink($log);
        }
    }

    public function testUnavailableReportingReturnsSanitizedPrivateResponse(): void
    {
        [$controller, $log] = $this->controller(
            new CurrentPrincipal('admin'),
            new DashboardReportReader($this->report(), true),
        );

        try {
            $response = $controller->index(Request::create('GET', '/admin/analytics?days=7&token=secret'));
            $logged = (string) file_get_contents($log);
            self::assertSame(503, $response->status());
            self::assertSame('no-store', $response->headers()['Cache-Control']);
            self::assertStringContainsString('temporarily unavailable', $response->body());
            self::assertStringContainsString('analytics_report_failed', $logged);
            self::assertStringContainsString(RuntimeException::class, $logged);
            self::assertStringNotContainsString('database password detail', $logged);
            self::assertStringNotContainsString('secret', $logged);
        } finally {
            unlink($log);
        }
    }

    public function testPrincipalProviderFactoryIsLazyAndCached(): void
    {
        $calls = 0;
        $provider = new LazyCurrentPrincipalProvider(static function () use (&$calls): CurrentPrincipalProvider {
            $calls++;
            return new DashboardPrincipalProvider(new CurrentPrincipal('admin'));
        });

        self::assertSame(0, $calls);
        self::assertSame('admin', $provider->current()?->authority);
        self::assertSame('admin', $provider->current()?->authority);
        self::assertSame(1, $calls);
    }

    /** @return array{AnalyticsController, string} */
    private function controller(?CurrentPrincipal $principal, AnalyticsReportReader $reader): array
    {
        $log = tempnam(sys_get_temp_dir(), 'n3-dashboard-log-');
        self::assertNotFalse($log);
        $root = dirname(__DIR__, 2);
        $ticks = [1_000_000_000, 1_050_000_000];

        return [new AnalyticsController(
            new View($root . '/resources/views'),
            new DashboardPrincipalProvider($principal),
            $reader,
            new AnalyticsVitals($root),
            new FileLogger($log),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-09-01 12:34:56', new DateTimeZone('UTC')),
            static function () use (&$ticks): int { return array_shift($ticks) ?? 1_050_000_000; },
        ), $log];
    }

    private function report(): AnalyticsReport
    {
        return new AnalyticsReport(
            new DateTimeImmutable('2026-08-02 12:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-09-01 12:00:00', new DateTimeZone('UTC')),
            [
                new AnalyticsRouteReport('public.page', 100, 1, 12_000_000, 700_000),
                new AnalyticsRouteReport('admin.analytics', 5, 0, 500_000, 150_000),
            ],
        );
    }
}

final readonly class DashboardPrincipalProvider implements CurrentPrincipalProvider
{
    public function __construct(private ?CurrentPrincipal $principal)
    {
    }

    public function current(): ?CurrentPrincipal
    {
        return $this->principal;
    }
}

final class DashboardReportReader implements AnalyticsReportReader
{
    public int $calls = 0;
    public ?DateTimeImmutable $since = null;
    public ?DateTimeImmutable $until = null;

    public function __construct(
        private readonly AnalyticsReport $report,
        private readonly bool $throw = false,
    ) {
    }

    public function report(DateTimeImmutable $since, DateTimeImmutable $until): AnalyticsReport
    {
        $this->calls++;
        $this->since = $since;
        $this->until = $until;
        if ($this->throw) {
            throw new RuntimeException('database password detail');
        }

        return new AnalyticsReport($since, $until, $this->report->routes);
    }
}
