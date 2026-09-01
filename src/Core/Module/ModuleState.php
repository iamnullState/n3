<?php

declare(strict_types=1);

namespace N3\Core\Module;

final readonly class ModuleState
{
    public function __construct(
        public string $id,
        public string $version,
        public string $manifestHash,
        public string $state,
    ) {
    }
}
