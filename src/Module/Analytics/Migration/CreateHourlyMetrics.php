<?php

declare(strict_types=1);

namespace N3\Module\Analytics\Migration;

use N3\Core\Module\ModuleMigration;
use N3\Module\Analytics\AnalyticsSchema;
use PDO;

final class CreateHourlyMetrics implements ModuleMigration
{
    public function moduleId(): string
    {
        return AnalyticsSchema::MODULE_ID;
    }

    public function version(): string
    {
        return '202609010001_create_hourly_metrics';
    }

    public function up(PDO $connection): void
    {
        $connection->exec(sprintf(
            'CREATE TABLE `%s` ('
            . 'bucket_start DATETIME NOT NULL, '
            . 'route_category VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'method VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, '
            . 'status_code SMALLINT UNSIGNED NOT NULL, '
            . 'request_count BIGINT UNSIGNED NOT NULL, '
            . 'total_duration_us BIGINT UNSIGNED NOT NULL, '
            . 'max_duration_us BIGINT UNSIGNED NOT NULL, '
            . 'PRIMARY KEY (bucket_start, route_category, method, status_code)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            AnalyticsSchema::hourlyMetricsTable(),
        ));
    }
}
