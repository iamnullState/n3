<?php

declare(strict_types=1);

namespace N3\Core\Module;

use RuntimeException;
use Throwable;

final class ModuleLifecycleFailed extends RuntimeException
{
    public function __construct(
        public readonly string $moduleId,
        public readonly string $phase,
        string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf('Module "%s" failed during %s: %s', $moduleId, $phase, $reason), previous: $previous);
    }
}
