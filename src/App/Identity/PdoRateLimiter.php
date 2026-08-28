<?php

declare(strict_types=1);

namespace N3\App\Identity;

use PDO;

final readonly class PdoRateLimiter implements RateLimiter
{
    public function __construct(private PDO $connection, private string $hashKey)
    {
    }

    public function allow(string $action, string $subject, int $limit, int $windowSeconds): bool
    {
        $window = intdiv(time(), $windowSeconds) * $windowSeconds;
        $hash = hash_hmac('sha256', $subject, $this->hashKey);
        $statement = $this->connection->prepare(
            'INSERT INTO rate_limit_buckets (action_key, subject_hash, window_start, attempts) '
            . 'VALUES (:action, :subject, :window, 1) ON DUPLICATE KEY UPDATE attempts = attempts + 1',
        );
        $statement->execute(['action' => $action, 'subject' => $hash, 'window' => $window]);
        $read = $this->connection->prepare(
            'SELECT attempts FROM rate_limit_buckets '
            . 'WHERE action_key = :action AND subject_hash = :subject AND window_start = :window',
        );
        $read->execute(['action' => $action, 'subject' => $hash, 'window' => $window]);

        return (int) $read->fetchColumn() <= $limit;
    }

    public function prune(int $olderThanEpoch): int
    {
        $statement = $this->connection->prepare('DELETE FROM rate_limit_buckets WHERE window_start < :cutoff');
        $statement->execute(['cutoff' => $olderThanEpoch]);

        return $statement->rowCount();
    }
}
