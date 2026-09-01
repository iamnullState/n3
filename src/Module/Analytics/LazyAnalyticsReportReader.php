<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use Closure;
use DateTimeImmutable;
use RuntimeException;

final class LazyAnalyticsReportReader implements AnalyticsReportReader
{
    private ?AnalyticsReportReader $reader = null;

    /** @param Closure(): AnalyticsReportReader $factory */
    public function __construct(private readonly Closure $factory)
    {
    }

    public function report(DateTimeImmutable $since, DateTimeImmutable $until): AnalyticsReport
    {
        if ($this->reader === null) {
            $reader = ($this->factory)();
            if (!$reader instanceof AnalyticsReportReader) {
                throw new RuntimeException('The Analytics report factory returned an invalid reader.');
            }
            $this->reader = $reader;
        }

        return $this->reader->report($since, $until);
    }
}
