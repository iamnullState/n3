<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Identity\PdoUserRepository;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\DatabaseException;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MariaDbFoundationTest extends TestCase
{
    private PDO $connection;
    private PDO $migrationConnection;
    private string $email;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }

        $variables = [
            'N3_TEST_DB_HOST',
            'N3_TEST_DB_PORT',
            'N3_TEST_DB_NAME',
            'N3_TEST_DB_USER',
            'N3_TEST_DB_PASSWORD',
            'N3_TEST_DB_MIGRATION_USER',
            'N3_TEST_DB_MIGRATION_PASSWORD',
        ];

        foreach ($variables as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }

        $database = (string) getenv('N3_TEST_DB_NAME');

        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }

        $runtimeConfig = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_USER'),
            (string) getenv('N3_TEST_DB_PASSWORD'),
        );
        $migrationConfig = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        );
        $factory = new ConnectionFactory();
        $this->connection = $factory->create($runtimeConfig);
        $this->migrationConnection = $factory->create($migrationConfig);
        (new MigrationRunner(
            $this->migrationConnection,
            dirname(__DIR__, 2) . '/database/migrations',
        ))->migrate();
        $this->email = sprintf('n3-%s@example.test', bin2hex(random_bytes(8)));
    }

    protected function tearDown(): void
    {
        if (isset($this->connection, $this->email)) {
            $statement = $this->connection->prepare('DELETE FROM users WHERE email_normalized = :email');
            $statement->execute(['email' => $this->email]);
        }
    }

    public function testRepositoryUsesPreparedStatementsAndFixedAccountAuthority(): void
    {
        $repository = new PdoUserRepository($this->connection);

        $id = (new TransactionManager($this->connection))->run(
            fn (): int => $repository->createPending(
                'N3 Test <script>',
                $this->email,
                $this->email,
                password_hash('not-a-real-password', PASSWORD_DEFAULT),
            ),
        );

        self::assertGreaterThan(0, $id);
        self::assertTrue($repository->normalizedEmailExists($this->email));

        $statement = $this->connection->prepare(
            'SELECT display_name, account_status, role_key FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();

        self::assertSame('N3 Test <script>', $row['display_name']);
        self::assertSame('pending_verification', $row['account_status']);
        self::assertSame('member', $row['role_key']);
    }

    public function testConnectionsUseUtcForPortableTimestampsAndLeases(): void
    {
        self::assertSame('+00:00', $this->connection->query('SELECT @@session.time_zone')->fetchColumn());
        self::assertSame('+00:00', $this->migrationConnection->query('SELECT @@session.time_zone')->fetchColumn());
    }

    public function testNormalizedEmailUniquenessIsEnforcedByMariaDb(): void
    {
        $repository = new PdoUserRepository($this->connection);
        $passwordHash = password_hash('not-a-real-password', PASSWORD_DEFAULT);

        $repository->createPending('First User', $this->email, $this->email, $passwordHash);

        $this->expectException(PDOException::class);
        $repository->createPending('Duplicate User', $this->email, $this->email, $passwordHash);
    }

    public function testFailedTransactionRollsBackThePendingUser(): void
    {
        $repository = new PdoUserRepository($this->connection);

        try {
            (new TransactionManager($this->connection))->run(
                function () use ($repository): never {
                    $repository->createPending(
                        'Rollback User',
                        $this->email,
                        $this->email,
                        password_hash('not-a-real-password', PASSWORD_DEFAULT),
                    );

                    throw new RuntimeException('Force rollback.');
                },
            );
        } catch (RuntimeException $exception) {
            self::assertSame('Force rollback.', $exception->getMessage());
        }

        self::assertFalse($repository->normalizedEmailExists($this->email));
    }

    /** @param callable(string): string $statement */
    #[DataProvider('runtimeSchemaMutations')]
    public function testRuntimeAccountCannotMutateSchema(callable $statement, bool $requiresTable): void
    {
        $table = 'n3_security_' . bin2hex(random_bytes(6));

        if ($requiresTable) {
            $this->migrationConnection->exec(sprintf(
                'CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB',
                $table,
            ));
        }

        try {
            $this->connection->exec($statement($table));
            self::fail('The runtime account performed a schema-changing statement.');
        } catch (PDOException $exception) {
            self::assertNotSame('', (string) $exception->getCode());
        } finally {
            $this->migrationConnection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
        }
    }

    /** @return iterable<string, array{callable(string): string, bool}> */
    public static function runtimeSchemaMutations(): iterable
    {
        yield 'create table' => [
            static fn (string $table): string => sprintf(
                'CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY)',
                $table,
            ),
            false,
        ];
        yield 'alter table' => [
            static fn (string $table): string => sprintf(
                'ALTER TABLE `%s` ADD COLUMN forbidden_value INT NULL',
                $table,
            ),
            true,
        ];
        yield 'drop table' => [
            static fn (string $table): string => sprintf('DROP TABLE `%s`', $table),
            true,
        ];
        yield 'create index' => [
            static fn (string $table): string => sprintf(
                'CREATE INDEX forbidden_index ON `%s` (id)',
                $table,
            ),
            true,
        ];
    }

    public function testRuntimeAccountCannotReadMariaDbPrivilegeData(): void
    {
        $this->expectException(PDOException::class);

        $this->connection->query('SELECT Host, User FROM mysql.user LIMIT 1');
    }

    public function testPreparedEmailLookupTreatsSqlAsData(): void
    {
        $this->email = "n3-' OR 1=1 --@example.test";
        $repository = new PdoUserRepository($this->connection);

        $repository->createPending(
            'Injection Test',
            $this->email,
            $this->email,
            password_hash('not-a-real-password', PASSWORD_DEFAULT),
        );

        self::assertTrue($repository->normalizedEmailExists($this->email));
        self::assertFalse($repository->normalizedEmailExists("' OR 1=1 --"));
    }

    public function testConnectionFailureDoesNotExposeTheRejectedPassword(): void
    {
        $rejectedPassword = 'n3-secret-that-must-not-appear-' . bin2hex(random_bytes(6));
        $config = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            (string) getenv('N3_TEST_DB_NAME'),
            (string) getenv('N3_TEST_DB_USER'),
            $rejectedPassword,
        );

        try {
            (new ConnectionFactory())->create($config);
            self::fail('MariaDB accepted the intentionally invalid password.');
        } catch (DatabaseException $exception) {
            self::assertSame('Unable to connect to MariaDB.', $exception->getMessage());
            self::assertStringNotContainsString($rejectedPassword, $exception->getMessage());
        }
    }
}
