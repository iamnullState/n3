<?php

declare(strict_types=1);

namespace N3\Core\Event;

interface EventListenerRegistry
{
    /** @param class-string $eventClass */
    public function listen(
        string $eventClass,
        string $moduleId,
        string $listenerId,
        callable $listener,
        int $priority = 0,
    ): void;
}
