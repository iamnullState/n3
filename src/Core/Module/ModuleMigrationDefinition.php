<?php

declare(strict_types=1);

namespace N3\Core\Module;

final readonly class ModuleMigrationDefinition
{
    public function __construct(
        public string $moduleId,
        public string $version,
        public string $checksum,
        public ModuleMigration $migration,
    ) {
    }
}
