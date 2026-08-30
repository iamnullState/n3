<?php

declare(strict_types=1);

namespace N3\Core\Event;

use DateTimeImmutable;

final readonly class CoreStarted
{
    public function __construct(
        public string $coreVersion,
        public DateTimeImmutable $occurredAt,
    ) {
    }
}
