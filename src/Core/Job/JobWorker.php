<?php

declare(strict_types=1);

namespace N3\Core\Job;

use DateTimeImmutable;
use DateTimeZone;
use LogicException;
use Throwable;

final class JobWorker
{
    /** @var array<string, JobHandler> */
    private array $handlers = [];

    /** @param list<JobHandler> $handlers */
    public function __construct(
        private readonly JobQueue $queue,
        array $handlers,
        private readonly string $owner,
        private readonly int $leaseSeconds = 300,
    ) {
        foreach ($handlers as $handler) {
            if (!$handler instanceof JobHandler) {
                throw new LogicException('Job handler configuration must contain JobHandler instances.');
            }
            if (!preg_match('/^[a-z0-9][a-z0-9.-]*\/[a-z0-9][a-z0-9.-]*$/D', $handler->moduleId())
                || !preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $handler->type())) {
                throw new LogicException('Job handlers must declare stable module and type identifiers.');
            }
            $key = $handler->moduleId() . ':' . $handler->type();
            if (isset($this->handlers[$key])) {
                throw new LogicException(sprintf('Duplicate job handler "%s".', $key));
            }
            $this->handlers[$key] = $handler;
        }
    }

    public function runOnce(?DateTimeImmutable $now = null): JobRunResult
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->queue->recoverExpired($now);
        $job = $this->queue->claim($this->owner, $now, $this->leaseSeconds);

        if ($job === null) {
            return new JobRunResult('idle');
        }

        $handler = $this->handlers[$job->moduleId . ':' . $job->type] ?? null;
        if ($handler === null) {
            $this->queue->dead($job, 'unknown_handler');
            return new JobRunResult('dead', $job->id, 'unknown_handler');
        }

        try {
            $handler->handle($job->payload);
            $this->queue->succeed($job);
            return new JobRunResult('succeeded', $job->id);
        } catch (PermanentJobFailure $exception) {
            $this->queue->dead($job, $exception->errorCode);
            return new JobRunResult('dead', $job->id, $exception->errorCode);
        } catch (RetryableJobFailure $exception) {
            return $this->retryOrDead($job, $exception->errorCode, max(1, min(3600, $exception->retryAfterSeconds)), $now);
        } catch (Throwable) {
            $delay = min(3600, 30 * (2 ** max(0, $job->attempt - 1)));
            return $this->retryOrDead($job, 'handler_exception', $delay, $now);
        }
    }

    private function retryOrDead(ClaimedJob $job, string $errorCode, int $delay, DateTimeImmutable $now): JobRunResult
    {
        if ($job->attempt >= $job->maxAttempts) {
            $this->queue->dead($job, $errorCode);
            return new JobRunResult('dead', $job->id, $errorCode);
        }

        $this->queue->retry($job, $errorCode, $now->modify(sprintf('+%d seconds', $delay)));
        return new JobRunResult('retry', $job->id, $errorCode);
    }
}
