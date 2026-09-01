<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use DateTimeImmutable;

interface AnalyticsReportReader
{
    public function report(DateTimeImmutable $since, DateTimeImmutable $until): AnalyticsReport;
}
