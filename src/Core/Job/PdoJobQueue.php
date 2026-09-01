<?php

declare(strict_types=1);

namespace N3\Core\Job;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use LogicException;
use N3\Core\Database\TransactionManager;
use PDO;

final readonly class PdoJobQueue implements JobQueue
{
    private const DATE_FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private PDO $connection,
        private TransactionManager $transactions,
    ) {
    }

    public function status(DateTimeImmutable $now): JobQueueStatus
    {
        $statement = $this->connection->prepare(
            "SELECT SUM(status = 'pending') AS pending, SUM(status = 'running') AS running, "
            . "SUM(status = 'succeeded') AS succeeded, SUM(status = 'dead') AS dead, "
            . "SUM(status = 'running' AND lease_expires_at <= :now) AS expired_leases FROM jobs",
        );
        $statement->execute(['now' => self::date($now)]);
        $row = $statement->fetch();

        return new JobQueueStatus(
            (int) ($row['pending'] ?? 0),
            (int) ($row['running'] ?? 0),
            (int) ($row['succeeded'] ?? 0),
            (int) ($row['dead'] ?? 0),
            (int) ($row['expired_leases'] ?? 0),
        );
    }

    public function enqueue(
        string $moduleId,
        string $type,
        array $payload,
        int $maxAttempts = 3,
        ?DateTimeImmutable $availableAt = null,
        ?string $idempotencyKey = null,
    ): int {
        self::assertIdentity($moduleId, $type);
        if ($maxAttempts < 1 || $maxAttempts > 25) {
            throw new LogicException('Job max attempts must be between 1 and 25.');
        }
        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 200)) {
            throw new LogicException('Job idempotency keys must contain between 1 and 200 bytes.');
        }

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $exception) {
            throw new LogicException('Job payload must be valid JSON.', previous: $exception);
        }
        if (strlen($json) > 65536) {
            throw new LogicException('Job payloads cannot exceed 64 KiB.');
        }

        return $this->transactions->run(function () use ($moduleId, $type, $json, $maxAttempts, $availableAt, $idempotencyKey): int {
            $statement = $this->connection->prepare(
                'INSERT INTO jobs '
                . '(module_id, job_type, payload_json, idempotency_key, max_attempts, available_at) '
                . 'VALUES (:module_id, :job_type, :payload_json, :idempotency_key, :max_attempts, :available_at) '
                . 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            );
            $statement->execute([
                'module_id' => $moduleId,
                'job_type' => $type,
                'payload_json' => $json,
                'idempotency_key' => $idempotencyKey === null ? null : hash('sha256', $idempotencyKey),
                'max_attempts' => $maxAttempts,
                'available_at' => self::date($availableAt ?? new DateTimeImmutable('now', new DateTimeZone('UTC'))),
            ]);
            $id = (int) $this->connection->lastInsertId();

            if ($statement->rowCount() === 1) {
                $this->record($id, 'enqueued', 0, null);
            }

            return $id;
        });
    }

    public function claim(string $owner, DateTimeImmutable $now, int $leaseSeconds): ?ClaimedJob
    {
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,99}$/D', $owner)) {
            throw new LogicException('Job worker owner identifiers are invalid.');
        }
        if ($leaseSeconds < 1 || $leaseSeconds > 3600) {
            throw new LogicException('Job leases must be between 1 and 3600 seconds.');
        }

        return $this->transactions->run(function () use ($owner, $now, $leaseSeconds): ?ClaimedJob {
            $statement = $this->connection->prepare(
                "SELECT id, module_id, job_type, payload_json, attempts, max_attempts FROM jobs "
                . "WHERE status = 'pending' AND available_at <= :now ORDER BY available_at, id LIMIT 1 FOR UPDATE SKIP LOCKED",
            );
            $statement->execute(['now' => self::date($now)]);
            $row = $statement->fetch();

            if ($row === false) {
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $attempt = (int) $row['attempts'] + 1;
            $update = $this->connection->prepare(
                "UPDATE jobs SET status = 'running', attempts = :attempts, lease_token = :lease_token, "
                . 'lease_owner = :lease_owner, lease_expires_at = :lease_expires_at '
                . "WHERE id = :id AND status = 'pending'",
            );
            $update->execute([
                'attempts' => $attempt,
                'lease_token' => $token,
                'lease_owner' => $owner,
                'lease_expires_at' => self::date($now->modify(sprintf('+%d seconds', $leaseSeconds))),
                'id' => $row['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new LogicException('The selected job could not be claimed.');
            }
            $this->record((int) $row['id'], 'claimed', $attempt, null);

            $payload = json_decode((string) $row['payload_json'], true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new LogicException('Stored job payload must decode to an object or array.');
            }

            return new ClaimedJob(
                (int) $row['id'],
                (string) $row['module_id'],
                (string) $row['job_type'],
                $payload,
                $attempt,
                (int) $row['max_attempts'],
                $token,
            );
        });
    }

    public function succeed(ClaimedJob $job): void
    {
        $this->finish($job, 'succeeded', null, null);
    }

    public function retry(ClaimedJob $job, string $errorCode, DateTimeImmutable $availableAt): void
    {
        self::assertErrorCode($errorCode);
        $this->finish($job, 'pending', $errorCode, $availableAt);
    }

    public function dead(ClaimedJob $job, string $errorCode): void
    {
        self::assertErrorCode($errorCode);
        $this->finish($job, 'dead', $errorCode, null);
    }

    public function recoverExpired(DateTimeImmutable $now): JobRecoveryResult
    {
        return $this->transactions->run(function () use ($now): JobRecoveryResult {
            $statement = $this->connection->prepare(
                "SELECT id, attempts, max_attempts FROM jobs WHERE status = 'running' "
                . 'AND lease_expires_at <= :now ORDER BY id FOR UPDATE SKIP LOCKED',
            );
            $statement->execute(['now' => self::date($now)]);
            $requeued = 0;
            $dead = 0;

            foreach ($statement->fetchAll() as $row) {
                $isDead = (int) $row['attempts'] >= (int) $row['max_attempts'];
                $update = $this->connection->prepare(
                    "UPDATE jobs SET status = :status, available_at = :available_at, lease_token = NULL, "
                    . "lease_owner = NULL, lease_expires_at = NULL, last_error_code = 'lease_expired', "
                    . 'completed_at = :completed_at WHERE id = :id AND status = \'running\'',
                );
                $update->execute([
                    'status' => $isDead ? 'dead' : 'pending',
                    'available_at' => self::date($now),
                    'completed_at' => $isDead ? self::date($now) : null,
                    'id' => $row['id'],
                ]);
                $this->record((int) $row['id'], $isDead ? 'dead' : 'recovered', (int) $row['attempts'], 'lease_expired');
                $isDead ? $dead++ : $requeued++;
            }

            return new JobRecoveryResult($requeued, $dead);
        });
    }

    public function retryDead(int $jobId, DateTimeImmutable $availableAt): bool
    {
        if ($jobId < 1) {
            throw new LogicException('Job IDs must be positive integers.');
        }

        return $this->transactions->run(function () use ($jobId, $availableAt): bool {
            $statement = $this->connection->prepare(
                "UPDATE jobs SET status = 'pending', attempts = 0, available_at = :available_at, "
                . 'lease_token = NULL, lease_owner = NULL, lease_expires_at = NULL, last_error_code = NULL, completed_at = NULL '
                . "WHERE id = :id AND status = 'dead'",
            );
            $statement->execute(['available_at' => self::date($availableAt), 'id' => $jobId]);
            if ($statement->rowCount() !== 1) {
                return false;
            }
            $this->record($jobId, 'retried', 0, 'operator_retry');

            return true;
        });
    }

    private function finish(
        ClaimedJob $job,
        string $status,
        ?string $errorCode,
        ?DateTimeImmutable $availableAt,
    ): void {
        $this->transactions->run(function () use ($job, $status, $errorCode, $availableAt): void {
            $statement = $this->connection->prepare(
                'UPDATE jobs SET status = :status, available_at = COALESCE(:available_at, available_at), '
                . 'lease_token = NULL, lease_owner = NULL, lease_expires_at = NULL, last_error_code = :error_code, '
                . "completed_at = CASE WHEN :terminal_status IN ('succeeded', 'dead') THEN CURRENT_TIMESTAMP(6) ELSE NULL END "
                . "WHERE id = :id AND status = 'running' AND lease_token = :lease_token",
            );
            $statement->execute([
                'status' => $status,
                'terminal_status' => $status,
                'available_at' => $availableAt === null ? null : self::date($availableAt),
                'error_code' => $errorCode,
                'id' => $job->id,
                'lease_token' => $job->leaseToken,
            ]);
            if ($statement->rowCount() !== 1) {
                throw new LogicException('Job completion requires its current lease token.');
            }
            $event = match ($status) {
                'succeeded' => 'succeeded',
                'pending' => 'retried',
                'dead' => 'dead',
                default => throw new LogicException('Unsupported job completion status.'),
            };
            $this->record($job->id, $event, $job->attempt, $errorCode);
        });
    }

    private function record(int $jobId, string $event, int $attempt, ?string $errorCode): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO job_events (job_id, event_type, attempt, error_code) '
            . 'VALUES (:job_id, :event_type, :attempt, :error_code)',
        );
        $statement->execute(['job_id' => $jobId, 'event_type' => $event, 'attempt' => $attempt, 'error_code' => $errorCode]);
    }

    private static function assertIdentity(string $moduleId, string $type): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*\/[a-z0-9][a-z0-9.-]*$/D', $moduleId)) {
            throw new LogicException('Job module IDs must use lowercase vendor/name format.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $type)) {
            throw new LogicException('Job types must use lowercase stable identifiers.');
        }
    }

    private static function assertErrorCode(string $errorCode): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $errorCode)) {
            throw new LogicException('Job error codes must use lowercase stable identifiers.');
        }
    }

    private static function date(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format(self::DATE_FORMAT);
    }
}
