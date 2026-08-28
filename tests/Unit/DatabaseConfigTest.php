<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\DatabaseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseConfigTest extends TestCase
{
    public function testItBuildsAUtf8mb4MariaDbDsnWithoutThePassword(): void
    {
        $config = new DatabaseConfig('127.0.0.1', 3306, 'n3_test', 'n3_app', 'secret');

        self::assertSame(
            'mysql:host=127.0.0.1;port=3306;dbname=n3_test;charset=utf8mb4',
            $config->dsn(),
        );
        self::assertStringNotContainsString('secret', $config->dsn());
    }

    public function testItRejectsDsnInjectionThroughTheDatabaseName(): void
    {
        $this->expectException(DatabaseException::class);

        new DatabaseConfig('127.0.0.1', 3306, 'n3;charset=latin1', 'n3_app', 'secret');
    }

    public function testItRejectsAnEmptyPassword(): void
    {
        $this->expectException(DatabaseException::class);

        new DatabaseConfig('127.0.0.1', 3306, 'n3', 'n3_app', '');
    }

    /**
     * @param array{host: string, port: int, database: string, username: string} $values
     */
    #[DataProvider('invalidConnectionValues')]
    public function testItRejectsInvalidConnectionValues(array $values): void
    {
        $this->expectException(DatabaseException::class);

        new DatabaseConfig(
            $values['host'],
            $values['port'],
            $values['database'],
            $values['username'],
            'secret',
        );
    }

    /** @return iterable<string, array{array{host: string, port: int, database: string, username: string}}> */
    public static function invalidConnectionValues(): iterable
    {
        yield 'host containing whitespace' => [[
            'host' => 'db host',
            'port' => 3306,
            'database' => 'n3',
            'username' => 'n3_app',
        ]];
        yield 'port below range' => [[
            'host' => '127.0.0.1',
            'port' => 0,
            'database' => 'n3',
            'username' => 'n3_app',
        ]];
        yield 'port above range' => [[
            'host' => '127.0.0.1',
            'port' => 65536,
            'database' => 'n3',
            'username' => 'n3_app',
        ]];
        yield 'empty database name' => [[
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => '',
            'username' => 'n3_app',
        ]];
        yield 'empty username' => [[
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'n3',
            'username' => '',
        ]];
        yield 'oversized username' => [[
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'n3',
            'username' => str_repeat('a', 129),
        ]];
    }

    public function testRuntimeAndMigrationCredentialsAreLoadedSeparately(): void
    {
        $_ENV['DB_HOST'] = 'localhost';
        $_ENV['DB_PORT'] = '3307';
        $_ENV['DB_NAME'] = 'n3_test';
        $_ENV['DB_USER'] = 'runtime_user';
        $_ENV['DB_PASSWORD'] = 'runtime_secret';
        $_ENV['DB_MIGRATION_USER'] = 'migration_user';
        $_ENV['DB_MIGRATION_PASSWORD'] = 'migration_secret';

        try {
            $runtime = DatabaseConfig::fromEnvironment();
            $migration = DatabaseConfig::fromMigrationEnvironment();

            self::assertSame('runtime_user', $runtime->username);
            self::assertSame('migration_user', $migration->username);
            self::assertSame('runtime_secret', $runtime->password());
            self::assertSame('migration_secret', $migration->password());
        } finally {
            unset(
                $_ENV['DB_HOST'],
                $_ENV['DB_PORT'],
                $_ENV['DB_NAME'],
                $_ENV['DB_USER'],
                $_ENV['DB_PASSWORD'],
                $_ENV['DB_MIGRATION_USER'],
                $_ENV['DB_MIGRATION_PASSWORD'],
            );
        }
    }
}
