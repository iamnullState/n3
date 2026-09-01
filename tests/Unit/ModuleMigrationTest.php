<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleGraph;
use N3\Core\Module\ModuleManifest;
use N3\Core\Module\ModuleMigration;
use N3\Core\Module\ModuleMigrationCatalog;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Service\ServiceRegistry;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ModuleMigrationTest extends TestCase
{
    public function testCatalogPreservesDependencyOrderAndSortsEachModuleVersion(): void
    {
        $dependency = new CatalogModule(
            new ModuleManifest('test/dependency', '1.0.0', '^0.2'),
            [
                new CatalogMigration('test/dependency', '202608310002_second'),
                new CatalogMigration('test/dependency', '202608310001_first'),
            ],
        );
        $consumer = new CatalogModule(
            new ModuleManifest('test/consumer', '1.0.0', '^0.2', ['test/dependency' => '^1.0']),
            [new CatalogMigration('test/consumer', '202608310001_create')],
        );
        $ordered = (new ModuleGraph('0.2.0'))->ordered([$consumer, $dependency]);

        $definitions = (new ModuleMigrationCatalog())->definitions($ordered);

        self::assertSame([
            'test/dependency:202608310001_first',
            'test/dependency:202608310002_second',
            'test/consumer:202608310001_create',
        ], array_map(
            static fn ($definition): string => $definition->moduleId . ':' . $definition->version,
            $definitions,
        ));
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $definitions[0]->checksum);
    }

    public function testCatalogRejectsMigrationOwnedByAnotherModule(): void
    {
        $module = new CatalogModule(
            new ModuleManifest('test/owner', '1.0.0', '^0.2'),
            [new CatalogMigration('test/other', '202608310001_create')],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not belong');
        (new ModuleMigrationCatalog())->definitions([$module]);
    }

    public function testCatalogRejectsDuplicateVersions(): void
    {
        $module = new CatalogModule(
            new ModuleManifest('test/duplicate', '1.0.0', '^0.2'),
            [
                new CatalogMigration('test/duplicate', '202608310001_create'),
                new CatalogMigration('test/duplicate', '202608310001_create'),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate migration version');
        (new ModuleMigrationCatalog())->definitions([$module]);
    }

    public function testCatalogRejectsAnonymousMigrationSources(): void
    {
        $migration = new class implements ModuleMigration {
            public function moduleId(): string
            {
                return 'test/anonymous';
            }

            public function version(): string
            {
                return '202608310001_create';
            }

            public function up(PDO $connection): void
            {
            }
        };
        $module = new CatalogModule(
            new ModuleManifest('test/anonymous', '1.0.0', '^0.2'),
            [$migration],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('readable named class');
        (new ModuleMigrationCatalog())->definitions([$module]);
    }
}

final readonly class CatalogModule implements Module, ModuleMigrationProvider
{
    /** @param list<ModuleMigration> $definitions */
    public function __construct(private ModuleManifest $definition, private array $definitions)
    {
    }

    public function manifest(): ModuleManifest
    {
        return $this->definition;
    }

    public function migrations(): array
    {
        return $this->definitions;
    }

    public function register(ServiceRegistry $services): void
    {
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }
}

final readonly class CatalogMigration implements ModuleMigration
{
    public function __construct(private string $owner, private string $migrationVersion)
    {
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
    }
}
