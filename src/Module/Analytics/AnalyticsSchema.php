<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use N3\Core\Module\ModuleResourcePolicy;

final class AnalyticsSchema
{
    public const MODULE_ID = 'n3/analytics';

    public static function hourlyMetricsTable(): string
    {
        return ModuleResourcePolicy::schemaPrefix(self::MODULE_ID) . 'hourly_metrics';
    }
}
