<?php

declare(strict_types=1);

namespace N3\Core\Database;

use PDO;

interface Migration
{
    public function version(): string;

    public function up(PDO $connection): void;

    public function down(PDO $connection): void;
}
