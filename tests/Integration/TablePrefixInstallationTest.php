<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Identity\PdoUserRepository;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\DatabaseException;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TableNames;
use N3\Core\Database\TablePrefixedPdo;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Module\Analytics\AnalyticsModule;
use N3\Module\Blog\BlogModule;
use N3\Module\Media\MediaModule;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class TablePrefixInstallationTest extends TestCase
{
    private ?TablePrefixedPdo $connection = null;
    private string $prefix = '';

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach ([
            'N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME',
            'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD',
        ] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }

        $this->prefix = 'p' . bin2hex(random_bytes(5)) . '_';
        $config = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
            $this->prefix,
        );
        $this->connection = new TablePrefixedPdo(
            $config->dsn(),
            $config->username,
            $config->password(),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ],
            $config->tableNames,
        );
        $this->connection->exec("SET time_zone = '+00:00'");
    }

    protected function tearDown(): void
    {
        if (!$this->connection instanceof TablePrefixedPdo || $this->prefix === '') {
            return;
        }

        $this->connection->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $statement = $this->connection->prepare(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :prefix',
            );
            $statement->execute(['prefix' => $this->prefix . '%']);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
                if (is_string($table) && str_starts_with($table, $this->prefix)
                    && preg_match('/^[a-z0-9_]+$/D', $table) === 1) {
                    $this->connection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
                }
            }
        } finally {
            $this->connection->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    public function testFreshPrefixedCoreAndModuleInstallIsIsolatedAndImmutable(): void
    {
        $root = dirname(__DIR__, 2);
        $runner = new MigrationRunner($this->connection, $root . '/database/migrations');

        self::assertCount(9, $runner->migrate());
        self::assertSame([], $runner->migrate());
        self::assertNotFalse($this->connection->query('SELECT COUNT(*) FROM users')->fetchColumn());
        self::assertSame(
            $this->prefix,
            $this->connection->query('SELECT table_prefix FROM installation_state WHERE id = 1')->fetchColumn(),
        );

        $moduleRunner = new ModuleMigrationRunner(
            $this->connection,
            [new AnalyticsModule(), new MediaModule(), new BlogModule()],
        );
        self::assertCount(4, $moduleRunner->migrate());
        self::assertSame([], $moduleRunner->migrate());

        $repository = new PdoUserRepository($this->connection);
        $email = 'prefix-' . bin2hex(random_bytes(6)) . '@example.test';
        $id = $repository->createPending('Prefix User', $email, $email, password_hash('a safe test passphrase', PASSWORD_DEFAULT));
        self::assertGreaterThan(0, $id);
        self::assertTrue($repository->normalizedEmailExists($email));

        $physicalTables = $this->physicalTables();
        foreach ([
            'users',
            'schema_migrations',
            'installation_state',
            'module_migrations',
            'm_n3_media_034553f6_assets',
            'm_n3_blog_0356bd27_posts',
        ] as $table) {
            self::assertContains($this->prefix . $table, $physicalTables, implode(', ', $physicalTables));
        }

        $same = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), (string) getenv('N3_TEST_DB_NAME'),
            (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'), $this->prefix,
        );
        self::assertInstanceOf(PDO::class, (new ConnectionFactory())->create($same));

        $wrong = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'), (int) getenv('N3_TEST_DB_PORT'), (string) getenv('N3_TEST_DB_NAME'),
            (string) getenv('N3_TEST_DB_MIGRATION_USER'), (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'), 'changed_',
        );
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('DB_TABLE_PREFIX');
        (new ConnectionFactory())->create($wrong);
    }

    /** @return list<string> */
    private function physicalTables(): array
    {
        $statement = $this->connection->prepare(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :prefix',
        );
        $statement->execute(['prefix' => $this->prefix . '%']);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
