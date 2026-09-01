<?php

declare(strict_types=1);

namespace N3\Core\Module;

interface ModuleLifecycleRepository
{
    /** @return array<string, ModuleState> */
    public function all(): array;

    public function apply(ModuleChange $change): void;
}
