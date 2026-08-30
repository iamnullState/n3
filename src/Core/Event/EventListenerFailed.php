<?php

declare(strict_types=1);

namespace N3\Core\Event;

use RuntimeException;
use Throwable;

final class EventListenerFailed extends RuntimeException
{
    public function __construct(
        public readonly string $moduleId,
        public readonly string $listenerId,
        public readonly string $eventClass,
        Throwable $previous,
    ) {
        parent::__construct(sprintf(
            'Module "%s" listener "%s" failed while handling "%s".',
            $moduleId,
            $listenerId,
            $eventClass,
        ), previous: $previous);
    }
}
