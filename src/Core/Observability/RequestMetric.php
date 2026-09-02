<?php

declare(strict_types=1);

namespace N3\Core\Observability;

use DateTimeImmutable;
use InvalidArgumentException;

final readonly class RequestMetric
{
    /** @var list<string> */
    public const ROUTE_CATEGORIES = [
        'public.home',
        'public.page',
        'public.media',
        'identity',
        'admin.pages',
        'admin.analytics',
        'admin.media',
        'api.system',
        'api.other',
        'other',
    ];

    public function __construct(
        public DateTimeImmutable $occurredAt,
        public string $routeCategory,
        public string $method,
        public int $statusCode,
        public int $durationMicroseconds,
    ) {
        if (!in_array($routeCategory, self::ROUTE_CATEGORIES, true)) {
            throw new InvalidArgumentException('Request metric route categories must use the controlled vocabulary.');
        }

        if (!preg_match('/^[A-Z]{3,8}$/D', $method)) {
            throw new InvalidArgumentException('Request metric methods must be normalized HTTP method tokens.');
        }

        if ($statusCode < 100 || $statusCode > 599) {
            throw new InvalidArgumentException('Request metric status codes must be valid HTTP status codes.');
        }

        if ($durationMicroseconds < 0 || $durationMicroseconds > 60_000_000) {
            throw new InvalidArgumentException('Request metric durations must be between zero and sixty seconds.');
        }
    }
}
