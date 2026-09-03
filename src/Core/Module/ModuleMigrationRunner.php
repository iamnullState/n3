<?php

declare(strict_types=1);

namespace N3\Core\Module;

use N3\Core\Database\DatabaseException;
use N3\Core\Database\TablePrefixedPdo;
use PDO;
use Throwable;

final readonly class ModuleMigrationRunner
{
    /** @param list<Module> $modules Dependency-ordered enabled modules. */
    public function __construct(
        private PDO $connection,
        private array $modules,
        private ModuleMigrationCatalog $catalog = new ModuleMigrationCatalog(),
    ) {
    }

    /** @return list<ModuleMigrationStatus> */
    public function status(): array
    {
        $definitions = $this->catalog->definitions($this->modules);
        $applied = $this->appliedMigrations();
        $this->verifyHistory($definitions, $applied);

        return array_map(
            static fn (ModuleMigrationDefinition $definition): ModuleMigrationStatus => new ModuleMigrationStatus(
                $definition->moduleId,
                $definition->version,
                isset($applied[$definition->moduleId][$definition->version]),
            ),
            $definitions,
        );
    }

    /** @return list<string> Fully qualified `module:version` identifiers. */
    public function migrate(): array
    {
        $lock = $this->lockName();
        $this->acquireLock($lock);

        try {
            $definitions = $this->catalog->definitions($this->modules);
            $applied = $this->appliedMigrations();
            $this->verifyHistory($definitions, $applied);
            $this->assertPendingMigrationsBelongToAForwardModuleVersion($definitions, $applied);
            $completed = [];

            foreach ($definitions as $definition) {
                if (isset($applied[$definition->moduleId][$definition->version])) {
                    continue;
                }

                try {
                    $definition->migration->up($this->connection);
                } catch (Throwable $exception) {
                    throw new ModuleMigrationFailed(
                        $definition->moduleId,
                        $definition->version,
                        'apply',
                        $exception,
                    );
                }

                try {
                    $statement = $this->connection->prepare(
                        'INSERT INTO module_migrations (module_id, migration_version, checksum) '
                        . 'VALUES (:module_id, :migration_version, :checksum)',
                    );
                    $statement->execute([
                        'module_id' => $definition->moduleId,
                        'migration_version' => $definition->version,
                        'checksum' => $definition->checksum,
                    ]);
                } catch (Throwable $exception) {
                    throw new ModuleMigrationFailed(
                        $definition->moduleId,
                        $definition->version,
                        'history recording',
                        $exception,
                    );
                }

                $completed[] = $definition->moduleId . ':' . $definition->version;
            }

            return $completed;
        } finally {
            $this->releaseLock($lock);
        }
    }

    /** @return array<string, array<string, string>> */
    private function appliedMigrations(): array
    {
        if (!$this->repositoryExists()) {
            throw new DatabaseException('Module migration history is unavailable; run Core migrations first.');
        }

        $statement = $this->connection->query(
            'SELECT module_id, migration_version, checksum FROM module_migrations '
            . 'ORDER BY module_id, migration_version',
        );
        if ($statement === false) {
            throw new DatabaseException('Unable to read module migration history.');
        }

        $applied = [];
        foreach ($statement->fetchAll() as $row) {
            $applied[(string) $row['module_id']][(string) $row['migration_version']] = (string) $row['checksum'];
        }

        return $applied;
    }

    /**
     * @param list<ModuleMigrationDefinition> $definitions
     * @param array<string, array<string, string>> $applied
     */
    private function verifyHistory(array $definitions, array $applied): void
    {
        $available = [];
        $enabledIds = [];
        foreach ($this->modules as $module) {
            $enabledIds[$module->manifest()->id] = true;
        }
        foreach ($definitions as $definition) {
            $available[$definition->moduleId][$definition->version] = $definition->checksum;
        }

        foreach ($applied as $moduleId => $versions) {
            if (!isset($enabledIds[$moduleId])) {
                continue;
            }
            foreach ($versions as $version => $checksum) {
                if (!isset($available[$moduleId][$version])) {
                    throw new DatabaseException(sprintf(
                        'Applied module migration "%s:%s" is missing from source.',
                        $moduleId,
                        $version,
                    ));
                }
                if (!hash_equals($checksum, $available[$moduleId][$version])) {
                    throw new DatabaseException(sprintf(
                        'Applied module migration "%s:%s" was modified.',
                        $moduleId,
                        $version,
                    ));
                }
            }
        }
    }

    private function repositoryExists(): bool
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM information_schema.tables "
            . 'WHERE table_schema = DATABASE() AND table_name = :table_name',
        );
        $statement->execute(['table_name' => $this->tableName('module_migrations')]);

        return $statement !== false && (int) $statement->fetchColumn() === 1;
    }

    /**
     * @param list<ModuleMigrationDefinition> $definitions
     * @param array<string, array<string, string>> $applied
     */
    private function assertPendingMigrationsBelongToAForwardModuleVersion(array $definitions, array $applied): void
    {
        $pendingByModule = [];
        foreach ($definitions as $definition) {
            if (!isset($applied[$definition->moduleId][$definition->version])) {
                $pendingByModule[$definition->moduleId] = true;
            }
        }
        if ($pendingByModule === [] || !$this->moduleRepositoryExists()) {
            return;
        }

        $installed = [];
        $statement = $this->connection->query('SELECT module_id, installed_version FROM modules');
        foreach ($statement->fetchAll() as $row) {
            $installed[(string) $row['module_id']] = (string) $row['installed_version'];
        }

        foreach ($this->modules as $module) {
            $manifest = $module->manifest();
            if (!isset($pendingByModule[$manifest->id], $installed[$manifest->id])) {
                continue;
            }
            $comparison = version_compare($manifest->version, $installed[$manifest->id]);
            if ($comparison <= 0) {
                throw new DatabaseException(sprintf(
                    'Pending migrations for module "%s" require a forward module version change.',
                    $manifest->id,
                ));
            }
        }
    }

    private function moduleRepositoryExists(): bool
    {
        $statement = $this->connection->prepare(
            "SELECT COUNT(*) FROM information_schema.tables "
            . 'WHERE table_schema = DATABASE() AND table_name = :table_name',
        );
        $statement->execute(['table_name' => $this->tableName('modules')]);

        return $statement !== false && (int) $statement->fetchColumn() === 1;
    }

    private function lockName(): string
    {
        $database = $this->connection->query('SELECT DATABASE()')?->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new DatabaseException('Unable to determine the module migration lock scope.');
        }

        $prefix = $this->connection instanceof TablePrefixedPdo
            ? $this->connection->tableNames()->prefix()
            : '';

        return 'n3:module-migrations:' . substr(hash('sha256', $database . "\0" . $prefix), 0, 32);
    }

    private function acquireLock(string $name): void
    {
        $statement = $this->connection->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => $name]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new DatabaseException('Another module migration process is already running.');
        }
    }

    private function releaseLock(string $name): void
    {
        try {
            $statement = $this->connection->prepare('SELECT RELEASE_LOCK(:name)');
            $statement->execute(['name' => $name]);
        } catch (Throwable) {
        }
    }

    private function tableName(string $logical): string
    {
        return $this->connection instanceof TablePrefixedPdo
            ? $this->connection->tableNames()->physical($logical)
            : $logical;
    }
}
