<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use DateTimeImmutable;
use LogicException;
use N3\Core\Job\ClaimedJob;
use N3\Core\Job\JobHandler;
use N3\Core\Job\JobQueue;
use N3\Core\Job\JobQueueStatus;
use N3\Core\Job\JobRecoveryResult;
use N3\Core\Job\JobWorker;
use N3\Core\Job\PermanentJobFailure;
use N3\Core\Job\RetryableJobFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use InvalidArgumentException;

final class JobWorkerTest extends TestCase
{
    public function testItCompletesAHandledJob(): void
    {
        $queue = new MemoryJobQueue($this->job());
        $handled = null;
        $handler = new TestJobHandler(static function (array $payload) use (&$handled): void {
            $handled = $payload;
        });

        $result = (new JobWorker($queue, [$handler], 'test-worker'))->runOnce(new DateTimeImmutable('2026-08-30T00:00:00Z'));

        self::assertSame('succeeded', $result->status);
        self::assertSame(['page_id' => 12], $handled);
        self::assertSame(['recover', 'claim', 'succeed'], $queue->calls);
    }

    public function testRetryableFailureSchedulesAnotherAttempt(): void
    {
        $queue = new MemoryJobQueue($this->job());
        $handler = new TestJobHandler(static function (): never {
            throw new RetryableJobFailure('service_busy', 90);
        });
        $now = new DateTimeImmutable('2026-08-30T00:00:00Z');

        $result = (new JobWorker($queue, [$handler], 'test-worker'))->runOnce($now);

        self::assertSame('retry', $result->status);
        self::assertSame('service_busy', $result->errorCode);
        self::assertSame('2026-08-30T00:01:30+00:00', $queue->retryAt?->format(DATE_ATOM));
    }

    public function testFinalAttemptAndPermanentFailuresBecomeDeadLetters(): void
    {
        $finalQueue = new MemoryJobQueue($this->job(attempt: 3, maxAttempts: 3));
        $retrying = new TestJobHandler(static function (): never {
            throw new RetryableJobFailure('still_busy');
        });
        $final = (new JobWorker($finalQueue, [$retrying], 'test-worker'))->runOnce();

        self::assertSame('dead', $final->status);
        self::assertSame('still_busy', $finalQueue->errorCode);

        $permanentQueue = new MemoryJobQueue($this->job());
        $permanent = new TestJobHandler(static function (): never {
            throw new PermanentJobFailure('invalid_target');
        });
        $result = (new JobWorker($permanentQueue, [$permanent], 'test-worker'))->runOnce();

        self::assertSame('dead', $result->status);
        self::assertSame('invalid_target', $permanentQueue->errorCode);
    }

    public function testUnexpectedHandlerDetailsAreReducedToAControlledCode(): void
    {
        $queue = new MemoryJobQueue($this->job());
        $handler = new TestJobHandler(static function (): never {
            throw new RuntimeException('credential=must-not-persist');
        });

        $result = (new JobWorker($queue, [$handler], 'test-worker'))->runOnce();

        self::assertSame('retry', $result->status);
        self::assertSame('handler_exception', $result->errorCode);
        self::assertSame('handler_exception', $queue->errorCode);
    }

    public function testUnknownHandlersBecomeDeadLettersWithoutExecutingCode(): void
    {
        $queue = new MemoryJobQueue($this->job(type: 'missing'));

        $result = (new JobWorker($queue, [], 'test-worker'))->runOnce();

        self::assertSame('dead', $result->status);
        self::assertSame('unknown_handler', $queue->errorCode);
    }

    public function testDuplicateHandlerIdentitiesAreRejected(): void
    {
        $this->expectException(LogicException::class);

        new JobWorker(new MemoryJobQueue(), [new TestJobHandler(), new TestJobHandler()], 'test-worker');
    }

    public function testHandlerFailureCodesMustBeControlledIdentifiers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RetryableJobFailure('secret detail is not a code');
    }

    private function job(string $type = 'probe', int $attempt = 1, int $maxAttempts = 3): ClaimedJob
    {
        return new ClaimedJob(42, 'n3/core-probe', $type, ['page_id' => 12], $attempt, $maxAttempts, str_repeat('a', 64));
    }
}

final class TestJobHandler implements JobHandler
{
    public function __construct(private readonly mixed $callback = null)
    {
    }

    public function moduleId(): string
    {
        return 'n3/core-probe';
    }

    public function type(): string
    {
        return 'probe';
    }

    public function handle(array $payload): void
    {
        if (is_callable($this->callback)) {
            ($this->callback)($payload);
        }
    }
}

final class MemoryJobQueue implements JobQueue
{
    /** @var list<string> */
    public array $calls = [];
    public ?string $errorCode = null;
    public ?DateTimeImmutable $retryAt = null;

    public function __construct(private ?ClaimedJob $next = null)
    {
    }

    public function status(DateTimeImmutable $now): JobQueueStatus
    {
        return new JobQueueStatus(0, 0, 0, 0, 0);
    }

    public function enqueue(string $moduleId, string $type, array $payload, int $maxAttempts = 3, ?DateTimeImmutable $availableAt = null, ?string $idempotencyKey = null): int
    {
        return 1;
    }

    public function claim(string $owner, DateTimeImmutable $now, int $leaseSeconds): ?ClaimedJob
    {
        $this->calls[] = 'claim';
        $job = $this->next;
        $this->next = null;
        return $job;
    }

    public function succeed(ClaimedJob $job): void
    {
        $this->calls[] = 'succeed';
    }

    public function retry(ClaimedJob $job, string $errorCode, DateTimeImmutable $availableAt): void
    {
        $this->calls[] = 'retry';
        $this->errorCode = $errorCode;
        $this->retryAt = $availableAt;
    }

    public function dead(ClaimedJob $job, string $errorCode): void
    {
        $this->calls[] = 'dead';
        $this->errorCode = $errorCode;
    }

    public function recoverExpired(DateTimeImmutable $now): JobRecoveryResult
    {
        $this->calls[] = 'recover';
        return new JobRecoveryResult(0, 0);
    }

    public function retryDead(int $jobId, DateTimeImmutable $availableAt): bool
    {
        return false;
    }
}
