<?php

declare(strict_types=1);

namespace N3\Core\Backup;

use N3\Core\Database\DatabaseConfig;

interface DatabaseBackupDriver
{
    /** @param list<string> $tables @return iterable<string> */
    public function export(DatabaseConfig $database, array $tables): iterable;

    /** @param iterable<string> $chunks */
    public function import(DatabaseConfig $database, iterable $chunks): void;
}
