<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\Migration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MigrationDefinitionTest extends TestCase
{
    #[DataProvider('migrationFiles')]
    public function testMigrationMatchesItsFilename(string $file): void
    {
        $migration = require $file;

        self::assertInstanceOf(Migration::class, $migration);
        self::assertSame(pathinfo($file, PATHINFO_FILENAME), $migration->version());
    }

    /** @return iterable<string, array{string}> */
    public static function migrationFiles(): iterable
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/';
        yield 'users' => [$path . '202608270001_create_users.php'];
        yield 'identity security' => [$path . '202608270002_create_identity_security.php'];
        yield 'authentication recovery' => [$path . '202608270003_create_authentication_recovery.php'];
        yield 'pages' => [$path . '202608300004_create_pages.php'];
        yield 'module lifecycle and jobs' => [$path . '202608300005_create_module_lifecycle_and_jobs.php'];
        yield 'webhook receipts' => [$path . '202608310006_create_webhook_receipts.php'];
        yield 'module migrations' => [$path . '202608310007_create_module_migrations.php'];
    }
}
