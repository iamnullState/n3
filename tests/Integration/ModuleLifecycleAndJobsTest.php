<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use DateTimeImmutable;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Job\ClaimedJob;
use N3\Core\Job\JobHandler;
use N3\Core\Job\JobWorker;
use N3\Core\Job\PdoJobQueue;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleLifecycleService;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\PdoModuleLifecycleRepository;
use N3\Core\Service\ServiceRegistry;
use N3\Module\CoreProbe\CoreProbeModule;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleLifecycleAndJobsTest extends TestCase
{
    private PDO $connection;
    private string $moduleId;

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
        $this->connection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_USER'),
            (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        $migrationConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        ));
        (new MigrationRunner($migrationConnection, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->moduleId = 'test/lifecycle-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!isset($this->connection, $this->moduleId)) {
            return;
        }

        $deleteEvents = $this->connection->prepare(
            'DELETE job_events FROM job_events INNER JOIN jobs ON jobs.id = job_events.job_id WHERE jobs.module_id = :module_id',
        );
        $deleteEvents->execute(['module_id' => $this->moduleId]);
        $deleteJobs = $this->connection->prepare('DELETE FROM jobs WHERE module_id = :module_id');
        $deleteJobs->execute(['module_id' => $this->moduleId]);
        $deleteModuleEvents = $this->connection->prepare('DELETE FROM module_events WHERE module_id = :module_id');
        $deleteModuleEvents->execute(['module_id' => $this->moduleId]);
        $deleteModule = $this->connection->prepare('DELETE FROM modules WHERE module_id = :module_id');
        $deleteModule->execute(['module_id' => $this->moduleId]);
    }

    public function testModuleSynchronizationRetainsStateAndAuditHistory(): void
    {
        $service = new ModuleLifecycleService(
            new PdoModuleLifecycleRepository($this->connection),
            new TransactionManager($this->connection),
        );
        $versionOne = new IntegrationLifecycleModule(new ModuleManifest($this->moduleId, '1.0.0', '^0.2'));

        $coreProbe = new CoreProbeModule();
        $install = $service->plan([$coreProbe, $versionOne]);
        $service->apply($install);
        self::assertSame([], $service->plan([$coreProbe, $versionOne]));

        $versionTwo = new IntegrationLifecycleModule(new ModuleManifest($this->moduleId, '1.1.0', '^0.2'));
        $service->apply($service->plan([$coreProbe, $versionTwo]));
        $service->apply($service->plan([$coreProbe]));

        $statement = $this->connection->prepare('SELECT installed_version, state FROM modules WHERE module_id = :module_id');
        $statement->execute(['module_id' => $this->moduleId]);
        self::assertSame(['installed_version' => '1.1.0', 'state' => 'disabled'], $statement->fetch());

        $events = $this->connection->prepare('SELECT event_type FROM module_events WHERE module_id = :module_id ORDER BY id');
        $events->execute(['module_id' => $this->moduleId]);
        self::assertSame(['installed', 'updated', 'disabled'], array_column($events->fetchAll(), 'event_type'));
    }

    public function testQueueEnqueueIsIdempotentAndClaimsAreExclusive(): void
    {
        $queue = $this->queue();
        $now = new DateTimeImmutable('2026-08-30T12:00:00Z');
        $firstId = $queue->enqueue($this->moduleId, 'probe', ['message' => 'safe'], 3, $now, 'same-operation');
        $secondId = $queue->enqueue($this->moduleId, 'probe', ['message' => 'changed'], 3, $now, 'same-operation');

        self::assertSame($firstId, $secondId);
        $job = $queue->claim('worker:one', $now, 300);
        self::assertInstanceOf(ClaimedJob::class, $job);
        self::assertSame(['message' => 'safe'], $job->payload);
        self::assertNull($queue->claim('worker:two', $now, 300));

        $queue->succeed($job);
        $status = $queue->status($now);
        self::assertSame(1, $status->succeeded);

        $events = $this->connection->prepare('SELECT event_type FROM job_events WHERE job_id = :job_id ORDER BY id');
        $events->execute(['job_id' => $firstId]);
        self::assertSame(['enqueued', 'claimed', 'succeeded'], array_column($events->fetchAll(), 'event_type'));
    }

    public function testCompletionRequiresTheCurrentLeaseToken(): void
    {
        $queue = $this->queue();
        $now = new DateTimeImmutable('2026-08-30T12:00:00Z');
        $queue->enqueue($this->moduleId, 'probe', [], 3, $now);
        $job = $queue->claim('worker:one', $now, 300);
        self::assertInstanceOf(ClaimedJob::class, $job);

        $this->expectException(\LogicException::class);
        $queue->succeed(new ClaimedJob(
            $job->id,
            $job->moduleId,
            $job->type,
            $job->payload,
            $job->attempt,
            $job->maxAttempts,
            str_repeat('0', 64),
        ));
    }

    public function testExpiredLeasesAreRequeuedThenDeadLetteredAtTheAttemptLimit(): void
    {
        $queue = $this->queue();
        $start = new DateTimeImmutable('2026-08-30T12:00:00Z');
        $queue->enqueue($this->moduleId, 'probe', [], 2, $start);
        self::assertNotNull($queue->claim('worker:one', $start, 1));

        $first = $queue->recoverExpired($start->modify('+2 seconds'));
        self::assertSame(1, $first->requeued);
        self::assertSame(0, $first->dead);
        self::assertNotNull($queue->claim('worker:two', $start->modify('+2 seconds'), 1));

        $second = $queue->recoverExpired($start->modify('+4 seconds'));
        self::assertSame(0, $second->requeued);
        self::assertSame(1, $second->dead);
        self::assertSame(1, $queue->status($start->modify('+4 seconds'))->dead);
    }

    public function testWorkerPersistsOnlySanitizedFailureCodesAndOperatorCanRetryDeadJob(): void
    {
        $queue = $this->queue();
        $now = new DateTimeImmutable('2026-08-30T12:00:00Z');
        $id = $queue->enqueue($this->moduleId, 'probe', [], 1, $now);
        $handler = new IntegrationFailingJobHandler($this->moduleId);

        $result = (new JobWorker($queue, [$handler], 'worker:one'))->runOnce($now);

        self::assertSame('dead', $result->status);
        $statement = $this->connection->prepare('SELECT status, last_error_code FROM jobs WHERE id = :id');
        $statement->execute(['id' => $id]);
        self::assertSame(['status' => 'dead', 'last_error_code' => 'handler_exception'], $statement->fetch());
        self::assertTrue($queue->retryDead($id, $now->modify('+1 minute')));
        self::assertFalse($queue->retryDead($id, $now));
    }

    private function queue(): PdoJobQueue
    {
        return new PdoJobQueue($this->connection, new TransactionManager($this->connection));
    }
}

final class IntegrationLifecycleModule implements Module
{
    public function __construct(private readonly ModuleManifest $definition)
    {
    }

    public function manifest(): ModuleManifest
    {
        return $this->definition;
    }

    public function register(ServiceRegistry $services): void
    {
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }
}

final readonly class IntegrationFailingJobHandler implements JobHandler
{
    public function __construct(private string $moduleId)
    {
    }

    public function moduleId(): string
    {
        return $this->moduleId;
    }

    public function type(): string
    {
        return 'probe';
    }

    public function handle(array $payload): void
    {
        throw new RuntimeException('credential=must-never-reach-job-state');
    }
}
