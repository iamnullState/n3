<?php

declare(strict_types=1);

namespace N3\Core\Module;

use PDO;

interface ModuleMigration
{
    public function moduleId(): string;

    public function version(): string;

    public function up(PDO $connection): void;
}
