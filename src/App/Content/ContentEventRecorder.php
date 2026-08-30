<?php

declare(strict_types=1);

namespace N3\App\Content;

interface ContentEventRecorder
{
    public function record(
        int $pageId,
        int $actorId,
        string $event,
        ?string $fromStatus,
        ?string $toStatus,
        string $requestId,
    ): void;
}
