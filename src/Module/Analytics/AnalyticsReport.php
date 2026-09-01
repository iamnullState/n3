<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class AnalyticsReport
{
    /** @param list<AnalyticsRouteReport> $routes */
    public function __construct(
        public DateTimeImmutable $since,
        public DateTimeImmutable $until,
        public array $routes,
    ) {
        if ($since >= $until) {
            throw new InvalidArgumentException('Analytics report windows must have an increasing range.');
        }
    }

    public function requestCount(): int
    {
        return array_sum(array_map(static fn (AnalyticsRouteReport $route): int => $route->requestCount, $this->routes));
    }

    public function serverErrorCount(): int
    {
        return array_sum(array_map(static fn (AnalyticsRouteReport $route): int => $route->serverErrorCount, $this->routes));
    }

    public function totalDurationMicroseconds(): int
    {
        return array_sum(array_map(static fn (AnalyticsRouteReport $route): int => $route->totalDurationMicroseconds, $this->routes));
    }

    public function averageDurationMicroseconds(): int
    {
        return $this->requestCount() === 0
            ? 0
            : (int) round($this->totalDurationMicroseconds() / $this->requestCount());
    }

    public function maximumDurationMicroseconds(): int
    {
        return $this->routes === []
            ? 0
            : max(array_map(static fn (AnalyticsRouteReport $route): int => $route->maximumDurationMicroseconds, $this->routes));
    }

    public function serverErrorRate(): float
    {
        return $this->requestCount() === 0 ? 0.0 : $this->serverErrorCount() / $this->requestCount();
    }
}
