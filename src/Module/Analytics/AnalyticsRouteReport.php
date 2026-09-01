<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use InvalidArgumentException;
use N3\Core\Observability\RequestMetric;

final readonly class AnalyticsRouteReport
{
    public function __construct(
        public string $routeCategory,
        public int $requestCount,
        public int $serverErrorCount,
        public int $totalDurationMicroseconds,
        public int $maximumDurationMicroseconds,
    ) {
        if (!in_array($routeCategory, RequestMetric::ROUTE_CATEGORIES, true)) {
            throw new InvalidArgumentException('Analytics reports must use controlled route categories.');
        }

        if ($requestCount < 0 || $serverErrorCount < 0 || $serverErrorCount > $requestCount
            || $totalDurationMicroseconds < 0 || $maximumDurationMicroseconds < 0) {
            throw new InvalidArgumentException('Analytics report totals cannot be negative or inconsistent.');
        }
    }

    public function averageDurationMicroseconds(): int
    {
        return $this->requestCount === 0
            ? 0
            : (int) round($this->totalDurationMicroseconds / $this->requestCount);
    }
}
