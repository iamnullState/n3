<?php

declare(strict_types=1);

namespace N3\Core\Module;

final readonly class ModuleMigrationStatus
{
    public function __construct(
        public string $moduleId,
        public string $version,
        public bool $applied,
    ) {
    }
}
