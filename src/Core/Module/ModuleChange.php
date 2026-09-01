<?php

declare(strict_types=1);

namespace N3\Core\Module;

final readonly class ModuleChange
{
    public function __construct(
        public string $action,
        public string $moduleId,
        public ?string $fromVersion,
        public ?string $toVersion,
        public ?string $fromState,
        public ?string $toState,
        public ?string $manifestHash = null,
    ) {
    }
}
