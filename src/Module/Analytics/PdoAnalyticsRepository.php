<?php

declare(strict_types=1);

namespace N3\Module\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Observability\RequestMetric;
use N3\Core\Observability\RequestMetricsSink;
use PDO;

final readonly class PdoAnalyticsRepository implements RequestMetricsSink
{
    public function __construct(private PDO $connection)
    {
    }

    public function record(RequestMetric $metric): void
    {
        $utc = $metric->occurredAt->setTimezone(new DateTimeZone('UTC'));
        $bucket = $utc->setTime((int) $utc->format('H'), 0, 0);
        $table = AnalyticsSchema::hourlyMetricsTable();
        $statement = $this->connection->prepare(sprintf(
            'INSERT INTO `%s` '
            . '(bucket_start, route_category, method, status_code, request_count, total_duration_us, max_duration_us) '
            . 'VALUES (:bucket_start, :route_category, :method, :status_code, 1, :total_duration, :max_duration) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'request_count = request_count + 1, '
            . 'total_duration_us = total_duration_us + VALUES(total_duration_us), '
            . 'max_duration_us = GREATEST(max_duration_us, VALUES(max_duration_us))',
            $table,
        ));
        $statement->execute([
            'bucket_start' => $bucket->format('Y-m-d H:i:s'),
            'route_category' => $metric->routeCategory,
            'method' => $metric->method,
            'status_code' => $metric->statusCode,
            'total_duration' => $metric->durationMicroseconds,
            'max_duration' => $metric->durationMicroseconds,
        ]);
    }

    /** @return list<AnalyticsSummary> */
    public function summarize(DateTimeImmutable $since): array
    {
        $statement = $this->connection->prepare(sprintf(
            'SELECT route_category, method, status_code, '
            . 'SUM(request_count) AS request_count, '
            . 'ROUND(SUM(total_duration_us) / SUM(request_count)) AS average_duration_us, '
            . 'MAX(max_duration_us) AS maximum_duration_us '
            . 'FROM `%s` WHERE bucket_start >= :since '
            . 'GROUP BY route_category, method, status_code '
            . 'ORDER BY route_category, method, status_code',
            AnalyticsSchema::hourlyMetricsTable(),
        ));
        $statement->execute(['since' => $this->bucketTimestamp($since)]);

        return array_map(
            static fn (array $row): AnalyticsSummary => new AnalyticsSummary(
                (string) $row['route_category'],
                (string) $row['method'],
                (int) $row['status_code'],
                (int) $row['request_count'],
                (int) $row['average_duration_us'],
                (int) $row['maximum_duration_us'],
            ),
            $statement->fetchAll(),
        );
    }

    public function countBefore(DateTimeImmutable $cutoff): int
    {
        $statement = $this->connection->prepare(sprintf(
            'SELECT COUNT(*) FROM `%s` WHERE bucket_start < :cutoff',
            AnalyticsSchema::hourlyMetricsTable(),
        ));
        $statement->execute(['cutoff' => $this->bucketTimestamp($cutoff)]);

        return (int) $statement->fetchColumn();
    }

    public function pruneBefore(DateTimeImmutable $cutoff): int
    {
        $statement = $this->connection->prepare(sprintf(
            'DELETE FROM `%s` WHERE bucket_start < :cutoff',
            AnalyticsSchema::hourlyMetricsTable(),
        ));
        $statement->execute(['cutoff' => $this->bucketTimestamp($cutoff)]);

        return $statement->rowCount();
    }

    private function bucketTimestamp(DateTimeImmutable $date): string
    {
        $utc = $date->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), 0, 0)->format('Y-m-d H:i:s');
    }
}
