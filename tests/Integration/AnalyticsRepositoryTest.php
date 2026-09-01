<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Core\Observability\RequestMetric;
use N3\Module\Analytics\AnalyticsModule;
use N3\Module\Analytics\AnalyticsSchema;
use N3\Module\Analytics\PdoAnalyticsRepository;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AnalyticsRepositoryTest extends TestCase
{
    private PDO $migrationConnection;
    private PDO $runtimeConnection;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach ([
            'N3_TEST_DB_HOST',
            'N3_TEST_DB_PORT',
            'N3_TEST_DB_NAME',
            'N3_TEST_DB_USER',
            'N3_TEST_DB_PASSWORD',
            'N3_TEST_DB_MIGRATION_USER',
            'N3_TEST_DB_MIGRATION_PASSWORD',
        ] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }

        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }

        $factory = new ConnectionFactory();
        $this->migrationConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        $this->runtimeConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_USER'),
            (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        (new MigrationRunner($this->migrationConnection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->removeAnalyticsSchema();
        (new ModuleMigrationRunner($this->migrationConnection, [new AnalyticsModule()]))->migrate();
    }

    protected function tearDown(): void
    {
        if (isset($this->migrationConnection)) {
            $this->removeAnalyticsSchema();
        }
    }

    public function testMigrationAndRepositoryAggregateWithoutVisitorLevelColumns(): void
    {
        $repository = new PdoAnalyticsRepository($this->runtimeConnection);
        $now = new DateTimeImmutable('2026-09-01 12:34:56', new DateTimeZone('UTC'));
        $repository->record(new RequestMetric($now, 'public.page', 'GET', 200, 1_000));
        $repository->record(new RequestMetric($now->modify('+10 minutes'), 'public.page', 'GET', 200, 3_000));
        $repository->record(new RequestMetric($now, 'identity', 'POST', 422, 9_000));
        $repository->record(new RequestMetric($now, 'admin.analytics', 'GET', 503, 7_000));
        $repository->record(new RequestMetric($now->modify('-100 days'), 'other', 'GET', 404, 500));

        $rows = $this->runtimeConnection->query(sprintf(
            'SELECT * FROM `%s` ORDER BY bucket_start, route_category',
            AnalyticsSchema::hourlyMetricsTable(),
        ))->fetchAll();
        self::assertCount(4, $rows);
        $page = array_values(array_filter($rows, static fn (array $row): bool => $row['route_category'] === 'public.page'))[0];
        self::assertSame(2, (int) $page['request_count']);
        self::assertSame(4_000, (int) $page['total_duration_us']);
        self::assertSame(3_000, (int) $page['max_duration_us']);

        $columns = array_column($this->migrationConnection->query(sprintf(
            'SHOW COLUMNS FROM `%s`',
            AnalyticsSchema::hourlyMetricsTable(),
        ))->fetchAll(), 'Field');
        self::assertSame([
            'bucket_start',
            'route_category',
            'method',
            'status_code',
            'request_count',
            'total_duration_us',
            'max_duration_us',
        ], $columns);
        foreach (['path', 'url', 'query', 'slug', 'ip', 'user_agent', 'referrer', 'user_id', 'session_id', 'request_id'] as $forbidden) {
            self::assertNotContains($forbidden, $columns);
        }

        $summary = $repository->summarize($now->modify('-7 days'));
        self::assertCount(3, $summary);
        self::assertSame(2_000, array_values(array_filter(
            $summary,
            static fn ($row): bool => $row->routeCategory === 'public.page',
        ))[0]->averageDurationMicroseconds);

        $report = $repository->report($now->modify('-7 days'), $now->modify('+1 hour'));
        self::assertSame(4, $report->requestCount());
        self::assertSame(1, $report->serverErrorCount());
        self::assertSame(5_000, $report->averageDurationMicroseconds());
        self::assertSame(9_000, $report->maximumDurationMicroseconds());
        self::assertSame(['admin.analytics', 'identity', 'public.page'], array_column($report->routes, 'routeCategory'));

        self::assertSame(1, $repository->countBefore($now->modify('-90 days')));
        self::assertSame(1, $repository->pruneBefore($now->modify('-90 days')));
        self::assertSame(0, $repository->countBefore($now->modify('-90 days')));
    }

    private function removeAnalyticsSchema(): void
    {
        $this->migrationConnection->exec(sprintf(
            'DROP TABLE IF EXISTS `%s`',
            AnalyticsSchema::hourlyMetricsTable(),
        ));
        $statement = $this->migrationConnection->prepare('DELETE FROM module_migrations WHERE module_id = :module_id');
        $statement->execute(['module_id' => AnalyticsSchema::MODULE_ID]);
        $this->migrationConnection->prepare('DELETE FROM module_events WHERE module_id = :module_id')
            ->execute(['module_id' => AnalyticsSchema::MODULE_ID]);
        $this->migrationConnection->prepare('DELETE FROM modules WHERE module_id = :module_id')
            ->execute(['module_id' => AnalyticsSchema::MODULE_ID]);
    }
}
