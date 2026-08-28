<?php

declare(strict_types=1);

namespace N3\Core\Database;

final readonly class MigrationStatus
{
    public function __construct(
        public string $version,
        public bool $applied,
    ) {
    }
}
