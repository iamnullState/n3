<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\App\Identity\AdminBootstrapService;
use N3\App\Identity\IdentityValidator;
use N3\App\Identity\UserRepository;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TransactionManager;
use N3\Core\Http\Request;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleLifecycleService;
use N3\Core\Module\ModuleMigrationRunner;
use N3\Core\Module\PdoModuleLifecycleRepository;
use PDO;
use RuntimeException;
use Throwable;

final readonly class InstallerService implements InstallerOperations
{
    /** @param list<Module> $modules */
    public function __construct(
        private string $root,
        private InstallerConfig $config,
        private DatabaseConfig $runtimeConfig,
        private DatabaseConfig $migrationConfig,
        private PDO $connection,
        private PDO $runtimeConnection,
        private InstallationStateRepository $state,
        private UserRepository $users,
        private array $modules,
        private InstallationLock $lock,
        private IdentityValidator $validator = new IdentityValidator(),
    ) {
    }

    public function status(): string
    {
        return $this->state->status();
    }

    public function preflight(Request $request): InstallerPreflight
    {
        $storageWritable = $this->ensurePrivateDirectory($this->root . '/storage/install')
            && $this->ensurePrivateDirectory($this->root . '/storage/sessions')
            && $this->ensurePrivateDirectory($this->root . '/storage/logs');
        $httpsRequired = $this->config->environment !== 'production' || $request->isSecure();
        $urlSecure = $this->config->environment !== 'production'
            || str_starts_with($this->config->appUrl, 'https://');
        $enabledIds = array_map(static fn (Module $module): string => $module->manifest()->id, $this->modules);
        $moduleExtensions = !in_array('n3/media', $enabledIds, true)
            || (extension_loaded('fileinfo') && extension_loaded('gd'));

        return new InstallerPreflight([
            'php' => version_compare(PHP_VERSION, '8.5.0', '>='),
            'pdo_mysql' => extension_loaded('pdo_mysql'),
            'mbstring' => extension_loaded('mbstring'),
            'installer_secrets' => true,
            'https' => $httpsRequired && $urlSecure,
            'private_storage' => $storageWritable,
            'separate_database_accounts' => !hash_equals($this->runtimeConfig->username, $this->migrationConfig->username),
            'database' => $this->connection->query('SELECT 1')?->fetchColumn() !== false
                && $this->runtimeConnection->query('SELECT 1')?->fetchColumn() !== false,
            'module_extensions' => $moduleExtensions,
        ], [
            'application_url' => $this->config->appUrl,
            'database_host' => $this->runtimeConfig->host,
            'database_port' => (string) $this->runtimeConfig->port,
            'database_name' => $this->runtimeConfig->database,
            'runtime_user' => $this->runtimeConfig->username,
            'migration_user' => $this->migrationConfig->username,
            'table_prefix' => $this->runtimeConfig->tableNames->prefix() === ''
                ? '(none)'
                : $this->runtimeConfig->tableNames->prefix(),
        ]);
    }

    public function applyMigrations(): void
    {
        $this->withAdvisoryLock('migrate', function (): void {
            (new MigrationRunner($this->connection, $this->root . '/database/migrations'))->migrate();
            (new ModuleMigrationRunner($this->connection, $this->modules))->migrate();
            $lifecycle = new ModuleLifecycleService(
                new PdoModuleLifecycleRepository($this->connection),
                new TransactionManager($this->connection),
            );
            $lifecycle->apply($lifecycle->plan($this->modules));

            if ($this->state->status() === 'migrations_pending') {
                throw new RuntimeException('Installation migrations did not reach a resumable state.');
            }
        });
    }

    public function validateAdmin(string $name, string $email, string $password, string $confirmation): array
    {
        return $this->validator->registrationErrors($name, $email, $password, $confirmation);
    }

    public function createAdmin(string $name, string $email, string $password): void
    {
        $this->withAdvisoryLock('admin', function () use ($name, $email, $password): void {
            (new AdminBootstrapService(
                $this->validator,
                $this->users,
                new TransactionManager($this->connection),
            ))->create($name, $email, $password);
        });
    }

    public function adminExists(): bool
    {
        return $this->users->adminExists();
    }

    public function complete(): void
    {
        if (!$this->adminExists()) {
            throw new RuntimeException('An administrator is required before installation can complete.');
        }
        $this->state->markComplete();
        $this->lock->create();
    }

    private function ensurePrivateDirectory(string $path): bool
    {
        if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) {
            return false;
        }
        @chmod($path, 0700);

        return is_writable($path);
    }

    private function withAdvisoryLock(string $operation, callable $callback): mixed
    {
        $database = $this->connection->query('SELECT DATABASE()')?->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('Unable to determine the installer lock scope.');
        }
        $lock = 'n3:install:' . substr(hash(
            'sha256',
            $database . "\0" . $this->migrationConfig->tableNames->prefix() . "\0" . $operation,
        ), 0, 40);
        $statement = $this->connection->prepare('SELECT GET_LOCK(:name, 0)');
        $statement->execute(['name' => $lock]);
        if ((int) $statement->fetchColumn() !== 1) {
            throw new RuntimeException('Another installation operation is already running.');
        }
        try {
            return $callback();
        } finally {
            try {
                $release = $this->connection->prepare('SELECT RELEASE_LOCK(:name)');
                $release->execute(['name' => $lock]);
            } catch (Throwable) {
            }
        }
    }
}
