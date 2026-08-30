<?php

declare(strict_types=1);

namespace N3\App\Content;

use PDO;

final readonly class PdoContentEventRecorder implements ContentEventRecorder
{
    public function __construct(private PDO $connection)
    {
    }

    public function record(
        int $pageId,
        int $actorId,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        string $requestId,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO content_events '
            . '(page_id, actor_user_id, event_type, from_status, to_status, request_id) '
            . 'VALUES (:page_id, :actor_id, :event_type, :from_status, :to_status, :request_id)',
        );
        $statement->execute([
            'page_id' => $pageId,
            'actor_id' => $actorId,
            'event_type' => $event,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'request_id' => $requestId,
        ]);
    }
}
