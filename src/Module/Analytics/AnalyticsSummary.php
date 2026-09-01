<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

final readonly class AnalyticsSummary
{
    public function __construct(
        public string $routeCategory,
        public string $method,
        public int $statusCode,
        public int $requestCount,
        public int $averageDurationMicroseconds,
        public int $maximumDurationMicroseconds,
    ) {
    }
}
