<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\View\View;
use Throwable;

final readonly class AnalyticsController
{
    private Closure $clock;
    private Closure $monotonicClock;

    public function __construct(
        private View $view,
        private CurrentPrincipalProvider $principals,
        private AnalyticsReportReader $reports,
        private AnalyticsVitals $vitals,
        private FileLogger $logger,
        ?Closure $clock = null,
        ?Closure $monotonicClock = null,
    ) {
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->monotonicClock = $monotonicClock ?? static fn (): int => hrtime(true);
    }

    public function index(Request $request): Response
    {
        $principal = $this->principals->current();
        if ($principal === null) {
            return $this->private(Response::redirect('/login'));
        }

        if ($principal->authority !== 'admin') {
            return $this->private(Response::html($this->view->render('errors/403', [
                'pageTitle' => 'Access denied',
                'metaDescription' => 'Access denied — N3',
            ]), 403));
        }

        $days = $this->days($request->query('days', '7'));
        if ($days === null) {
            return $this->private(Response::html($this->view->render('analytics/error', [
                'pageTitle' => 'Invalid analytics period — N3',
                'metaDescription' => 'The Analytics report period is invalid.',
                'message' => 'Choose a report period of 1, 7, 30, or 90 days.',
            ]), 400));
        }

        $now = ($this->clock)()->setTimezone(new DateTimeZone('UTC'));
        $until = $now->setTime((int) $now->format('H'), 0, 0);
        $since = $until->modify(sprintf('-%d days', $days));

        try {
            $started = ($this->monotonicClock)();
            $report = $this->reports->report($since, $until);
            $queryMicroseconds = max(0, intdiv(($this->monotonicClock)() - $started, 1_000));
            $assessments = $this->vitals->assess($report, $queryMicroseconds);

            return $this->private(Response::html($this->view->render('analytics/dashboard', [
                'pageTitle' => 'Analytics — N3',
                'metaDescription' => 'Private aggregate N3 Analytics and development vitals.',
                'robots' => 'noindex,nofollow',
                'days' => $days,
                'periods' => [1, 7, 30, 90],
                'report' => $report,
                'assessments' => $assessments,
            ])));
        } catch (Throwable $exception) {
            $this->logger->error('analytics_report_failed', ['exception' => $exception::class]);

            return $this->private(Response::html($this->view->render('analytics/error', [
                'pageTitle' => 'Analytics unavailable — N3',
                'metaDescription' => 'Analytics is temporarily unavailable.',
                'message' => 'Analytics is temporarily unavailable. Try again after checking module migrations and database access.',
            ]), 503));
        }
    }

    private function days(mixed $value): ?int
    {
        if (!is_string($value) || !in_array($value, ['1', '7', '30', '90'], true)) {
            return null;
        }

        return (int) $value;
    }

    private function private(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
