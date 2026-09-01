<?php

declare(strict_types=1);

namespace N3\Core\Module;

use RuntimeException;
use Throwable;

final class ModuleMigrationFailed extends RuntimeException
{
    public function __construct(
        public readonly string $moduleId,
        public readonly string $migrationVersion,
        public readonly string $phase,
        ?Throwable $previous = null,
    ) {
        parent::__construct(sprintf(
            'Module migration "%s:%s" failed during %s.',
            $moduleId,
            $migrationVersion,
            $phase,
        ), previous: $previous);
    }
}
