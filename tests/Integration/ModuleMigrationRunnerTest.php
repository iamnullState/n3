<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\Core\Database\ConnectionFactory;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\DatabaseException;
use N3\Core\Database\MigrationRunner;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\ModuleMigration;
use N3\Core\Module\ModuleMigrationFailed;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Core\Module\ModuleResourcePolicy;
use N3\Core\Service\ServiceRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleMigrationRunnerTest extends TestCase
{
    private PDO $migrationConnection;
    private PDO $runtimeConnection;
    private DatabaseConfig $migrationConfig;
    private string $moduleId;
    private string $table;

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach ([
            'N3_TEST_DB_HOST',
            'N3_TEST_DB_PORT',
            'N3_TEST_DB_NAME',
            'N3_TEST_DB_USER',
            'N3_TEST_DB_PASSWORD',
            'N3_TEST_DB_MIGRATION_USER',
            'N3_TEST_DB_MIGRATION_PASSWORD',
        ] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }

        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }

        $factory = new ConnectionFactory();
        $this->migrationConfig = new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_MIGRATION_USER'),
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
        );
        $this->migrationConnection = $factory->create($this->migrationConfig);
        $this->runtimeConnection = $factory->create(new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            $database,
            (string) getenv('N3_TEST_DB_USER'),
            (string) getenv('N3_TEST_DB_PASSWORD'),
        ));
        (new MigrationRunner(
            $this->migrationConnection,
            dirname(__DIR__, 2) . '/database/migrations',
        ))->migrate();

        $this->moduleId = 'test/migration-' . bin2hex(random_bytes(5));
        $this->table = ModuleResourcePolicy::schemaPrefix($this->moduleId) . 'probe';
    }

    protected function tearDown(): void
    {
        if (!isset($this->migrationConnection, $this->moduleId, $this->table)) {
            return;
        }

        $this->migrationConnection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $this->table));
        $statement = $this->migrationConnection->prepare('DELETE FROM module_migrations WHERE module_id = :module_id');
        $statement->execute(['module_id' => $this->moduleId]);
        $this->migrationConnection->prepare('DELETE FROM module_events WHERE module_id = :module_id')
            ->execute(['module_id' => $this->moduleId]);
        $this->migrationConnection->prepare('DELETE FROM modules WHERE module_id = :module_id')
            ->execute(['module_id' => $this->moduleId]);
    }

    public function testMigrationsApplyInOrderOnceAndRecordChecksums(): void
    {
        $module = $this->module([
            new IntegrationTableMigration(
                $this->moduleId,
                '202608310002_add_value',
                sprintf('ALTER TABLE `%s` ADD value_text VARCHAR(50) NULL', $this->table),
            ),
            new IntegrationTableMigration(
                $this->moduleId,
                '202608310001_create',
                sprintf('CREATE TABLE `%s` (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
            ),
        ]);
        $runner = new ModuleMigrationRunner($this->migrationConnection, [$module]);

        self::assertSame([false, false], array_column($runner->status(), 'applied'));
        self::assertSame([
            $this->moduleId . ':202608310001_create',
            $this->moduleId . ':202608310002_add_value',
        ], $runner->migrate());
        self::assertSame([true, true], array_column($runner->status(), 'applied'));
        self::assertSame([], $runner->migrate());

        $columns = $this->migrationConnection->query(sprintf('SHOW COLUMNS FROM `%s`', $this->table))->fetchAll();
        self::assertSame(['id', 'value_text'], array_column($columns, 'Field'));
        $history = $this->migrationConnection->prepare(
            'SELECT checksum FROM module_migrations WHERE module_id = :module_id ORDER BY migration_version',
        );
        $history->execute(['module_id' => $this->moduleId]);
        self::assertCount(2, $history->fetchAll());
    }

    public function testChangedAndMissingAppliedMigrationsFailClosed(): void
    {
        $module = $this->module([new IntegrationTableMigration(
            $this->moduleId,
            '202608310001_create',
            sprintf('CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
        )]);
        $runner = new ModuleMigrationRunner($this->migrationConnection, [$module]);
        $runner->migrate();

        $change = $this->migrationConnection->prepare(
            'UPDATE module_migrations SET checksum = :checksum WHERE module_id = :module_id',
        );
        $change->execute(['checksum' => str_repeat('0', 64), 'module_id' => $this->moduleId]);

        try {
            $runner->status();
            self::fail('Modified module migration history was accepted.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('was modified', $exception->getMessage());
        }

        $this->migrationConnection->prepare('DELETE FROM module_migrations WHERE module_id = :module_id')
            ->execute(['module_id' => $this->moduleId]);
        $this->migrationConnection->prepare(
            'INSERT INTO module_migrations (module_id, migration_version, checksum) VALUES (:module_id, :version, :checksum)',
        )->execute([
            'module_id' => $this->moduleId,
            'version' => '202608310000_missing',
            'checksum' => str_repeat('a', 64),
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('missing from source');
        $runner->status();
    }

    public function testFailureIsControlledAndDoesNotRecordCompletion(): void
    {
        $module = $this->module([new IntegrationFailingMigration($this->moduleId)]);
        $runner = new ModuleMigrationRunner($this->migrationConnection, [$module]);

        try {
            $runner->migrate();
            self::fail('Failing module migration completed.');
        } catch (ModuleMigrationFailed $exception) {
            self::assertSame($this->moduleId, $exception->moduleId);
            self::assertSame('apply', $exception->phase);
            self::assertStringNotContainsString('private database detail', $exception->getMessage());
        }

        $statement = $this->migrationConnection->prepare(
            'SELECT COUNT(*) FROM module_migrations WHERE module_id = :module_id',
        );
        $statement->execute(['module_id' => $this->moduleId]);
        self::assertSame(0, (int) $statement->fetchColumn());
    }

    public function testRuntimeAccountCannotExecuteModuleDdl(): void
    {
        $module = $this->module([new IntegrationTableMigration(
            $this->moduleId,
            '202608310001_create',
            sprintf('CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
        )]);

        $this->expectException(ModuleMigrationFailed::class);
        (new ModuleMigrationRunner($this->runtimeConnection, [$module]))->migrate();
    }

    public function testExistingModuleRequiresVersionIncreaseBeforeNewMigration(): void
    {
        $insert = $this->migrationConnection->prepare(
            'INSERT INTO modules (module_id, installed_version, manifest_hash, state) '
            . 'VALUES (:module_id, :version, :manifest_hash, :state)',
        );
        $insert->execute([
            'module_id' => $this->moduleId,
            'version' => '1.0.0',
            'manifest_hash' => str_repeat('a', 64),
            'state' => 'enabled',
        ]);
        $module = $this->module([new IntegrationTableMigration(
            $this->moduleId,
            '202608310001_create',
            sprintf('CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
        )]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('require a forward module version change');
        (new ModuleMigrationRunner($this->migrationConnection, [$module]))->migrate();
    }

    public function testForwardModuleVersionMayApplyPendingMigration(): void
    {
        $this->insertModuleState('1.0.0');
        $module = $this->module([new IntegrationTableMigration(
            $this->moduleId,
            '202608310001_create',
            sprintf('CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
        )], '1.1.0');

        self::assertSame(
            [$this->moduleId . ':202608310001_create'],
            (new ModuleMigrationRunner($this->migrationConnection, [$module]))->migrate(),
        );
    }

    public function testRemovingProviderWhileHistoryExistsFailsClosed(): void
    {
        $insert = $this->migrationConnection->prepare(
            'INSERT INTO module_migrations (module_id, migration_version, checksum) '
            . 'VALUES (:module_id, :version, :checksum)',
        );
        $insert->execute([
            'module_id' => $this->moduleId,
            'version' => '202608310001_create',
            'checksum' => str_repeat('a', 64),
        ]);

        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('missing from source');
        (new ModuleMigrationRunner(
            $this->migrationConnection,
            [new IntegrationMigrationlessModule(new ModuleManifest($this->moduleId, '1.0.0', '^0.2'))],
        ))->status();
    }

    public function testCoreRollbackCannotEraseNonemptyModuleMigrationHistory(): void
    {
        $module = $this->module([new IntegrationTableMigration(
            $this->moduleId,
            '202608310001_create',
            sprintf('CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
        )]);
        (new ModuleMigrationRunner($this->migrationConnection, [$module]))->migrate();
        $registryMigration = require dirname(__DIR__, 2)
            . '/database/migrations/202608310007_create_module_migrations.php';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('history cannot be dropped');
        $registryMigration->down($this->migrationConnection);
    }

    public function testConcurrentMigrationProcessFailsWithoutApplyingDdl(): void
    {
        $database = (string) $this->migrationConnection->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'n3:module-migrations:' . substr(hash('sha256', $database), 0, 32);
        $lockStatement = $this->migrationConnection->prepare('SELECT GET_LOCK(:name, 0)');
        $lockStatement->execute(['name' => $lock]);
        self::assertSame(1, (int) $lockStatement->fetchColumn());
        $module = $this->module([new IntegrationTableMigration(
            $this->moduleId,
            '202608310001_create',
            sprintf('CREATE TABLE `%s` (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB', $this->table),
        )]);

        try {
            $otherConnection = (new ConnectionFactory())->create($this->migrationConfig);
            (new ModuleMigrationRunner($otherConnection, [$module]))->migrate();
            self::fail('A concurrent migration process acquired an existing lock.');
        } catch (DatabaseException $exception) {
            self::assertStringContainsString('already running', $exception->getMessage());
        } finally {
            $release = $this->migrationConnection->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lock]);
        }
    }

    /** @param list<ModuleMigration> $migrations */
    private function module(array $migrations, string $version = '1.0.0'): IntegrationMigrationModule
    {
        return new IntegrationMigrationModule(
            new ModuleManifest($this->moduleId, $version, '^0.2'),
            $migrations,
        );
    }

    private function insertModuleState(string $version): void
    {
        $insert = $this->migrationConnection->prepare(
            'INSERT INTO modules (module_id, installed_version, manifest_hash, state) '
            . 'VALUES (:module_id, :version, :manifest_hash, :state)',
        );
        $insert->execute([
            'module_id' => $this->moduleId,
            'version' => $version,
            'manifest_hash' => str_repeat('a', 64),
            'state' => 'enabled',
        ]);
    }
}

final readonly class IntegrationMigrationModule implements Module, ModuleMigrationProvider
{
    /** @param list<ModuleMigration> $ownedMigrations */
    public function __construct(private ModuleManifest $definition, private array $ownedMigrations)
    {
    }

    public function manifest(): ModuleManifest
    {
        return $this->definition;
    }

    public function migrations(): array
    {
        return $this->ownedMigrations;
    }

    public function register(ServiceRegistry $services): void
    {
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }
}

final readonly class IntegrationTableMigration implements ModuleMigration
{
    public function __construct(
        private string $owner,
        private string $migrationVersion,
        private string $statement,
    ) {
    }

    public function moduleId(): string
    {
        return $this->owner;
    }

    public function version(): string
    {
        return $this->migrationVersion;
    }

    public function up(PDO $connection): void
    {
        $connection->exec($this->statement);
    }
}

final readonly class IntegrationMigrationlessModule implements Module
{
    public function __construct(private ModuleManifest $definition)
    {
    }

    public function manifest(): ModuleManifest
    {
        return $this->definition;
    }

    public function register(ServiceRegistry $services): void
    {
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }
}

final readonly class IntegrationFailingMigration implements ModuleMigration
{
    public function __construct(private string $owner)
    {
    }

    public function moduleId(): string
    {
        return $this->owner;
    }

    public function version(): string
    {
        return '202608310001_fail';
    }

    public function up(PDO $connection): void
    {
        throw new RuntimeException('private database detail');
    }
}
