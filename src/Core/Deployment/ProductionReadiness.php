<?php

declare(strict_types=1);

namespace N3\Core\Deployment;

use N3\App\Install\InstallationStateRepository;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleLifecycleService;
use N3\Core\Module\ModuleMigrationRunner;
use PDO;
use Throwable;

final readonly class ProductionReadiness
{
    /** @param array{environment: string, debug: bool} $app @param list<Module> $modules */
    public function __construct(
        private string $root,
        private array $app,
        private DatabaseConfig $runtimeConfig,
        private DatabaseConfig $migrationConfig,
        private PDO $runtimeConnection,
        private PDO $migrationConnection,
        private InstallationStateRepository $installation,
        private ModuleLifecycleService $moduleLifecycle,
        private array $modules,
    ) {
    }

    /** @return array<string, bool> */
    public function checks(): array
    {
        $checks = [
            'production_environment' => ProductionGuard::violations($this->root, $this->app, true) === [],
            'separate_database_accounts' => !hash_equals(
                $this->runtimeConfig->username,
                $this->migrationConfig->username,
            ),
            'runtime_database_connection' => $this->passes(
                fn (): bool => $this->runtimeConnection->query('SELECT 1')?->fetchColumn() !== false,
            ),
            'migration_database_connection' => $this->passes(
                fn (): bool => $this->migrationConnection->query('SELECT 1')?->fetchColumn() !== false,
            ),
            'installation_complete' => $this->passes(fn (): bool => $this->installation->isComplete()),
            'apache_protection_files' => is_file($this->root . '/.htaccess')
                && is_file($this->root . '/public/.htaccess'),
            'core_migrations_current' => false,
            'module_migrations_current' => false,
            'module_lifecycle_current' => false,
            'enabled_module_extensions' => $this->moduleExtensionsReady(),
        ];

        try {
            $checks['core_migrations_current'] = !in_array(
                false,
                array_map(
                    static fn ($status): bool => $status->applied,
                    (new MigrationRunner($this->migrationConnection, $this->root . '/database/migrations'))->status(),
                ),
                true,
            );
            $checks['module_migrations_current'] = !in_array(
                false,
                array_map(
                    static fn ($status): bool => $status->applied,
                    (new ModuleMigrationRunner($this->migrationConnection, $this->modules))->status(),
                ),
                true,
            );
            $checks['module_lifecycle_current'] = $this->moduleLifecycle->plan($this->modules) === [];
        } catch (Throwable) {
            // Individual deployment checks remain failed without exposing database details.
        }

        return $checks;
    }

    private function moduleExtensionsReady(): bool
    {
        foreach ($this->modules as $module) {
            if ($module->manifest()->id === 'n3/media'
                && (!extension_loaded('fileinfo') || !extension_loaded('gd'))) {
                return false;
            }
        }

        return true;
    }

    private function passes(callable $check): bool
    {
        try {
            return $check() === true;
        } catch (Throwable) {
            return false;
        }
    }
}
