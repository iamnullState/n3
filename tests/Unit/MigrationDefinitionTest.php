<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\Migration;
use PHPUnit\Framework\TestCase;

final class MigrationDefinitionTest extends TestCase
{
    public function testUserMigrationMatchesItsFilename(): void
    {
        $file = dirname(__DIR__, 2) . '/database/migrations/202608270001_create_users.php';
        $migration = require $file;

        self::assertInstanceOf(Migration::class, $migration);
        self::assertSame(pathinfo($file, PATHINFO_FILENAME), $migration->version());
    }
}
