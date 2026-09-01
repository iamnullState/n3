<?php

declare(strict_types=1);

namespace N3\Core\Module;

interface ModuleMigrationProvider
{
    /** @return list<ModuleMigration> */
    public function migrations(): array;
}
