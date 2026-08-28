<?php

declare(strict_types=1);

namespace N3\App\Identity;

use PDO;

final readonly class PdoSecurityEventRecorder implements SecurityEventRecorder
{
    public function __construct(private PDO $connection, private string $hashKey)
    {
    }

    public function record(
        string $event,
        string $outcome,
        string $subject,
        string $ip,
        ?int $userId,
        string $requestId,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO security_events '
            . '(user_id, event_type, outcome, subject_hash, ip_hash, request_id) '
            . 'VALUES (:user_id, :event, :outcome, :subject, :ip, :request_id)',
        );
        $statement->execute([
            'user_id' => $userId,
            'event' => $event,
            'outcome' => $outcome,
            'subject' => $subject === '' ? null : hash_hmac('sha256', $subject, $this->hashKey),
            'ip' => hash_hmac('sha256', $ip, $this->hashKey),
            'request_id' => $requestId,
        ]);
    }
}
