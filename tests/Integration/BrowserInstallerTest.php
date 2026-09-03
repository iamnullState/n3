<?php

declare(strict_types=1);

namespace N3\Tests\Integration;

use N3\App\Identity\PdoUserRepository;
use N3\App\Identity\AdminBootstrapService;
use N3\App\Identity\IdentityValidator;
use N3\App\Install\InstallationLock;
use N3\App\Install\InstallerConfig;
use N3\App\Install\InstallerService;
use N3\App\Install\PdoInstallationStateRepository;
use N3\Core\Database\DatabaseConfig;
use N3\Core\Database\MigrationRunner;
use N3\Core\Database\TablePrefixedPdo;
use N3\Core\Database\TransactionManager;
use N3\Core\Deployment\ProductionReadiness;
use N3\Core\Http\Request;
use N3\Core\Module\ModuleLifecycleService;
use N3\Core\Module\PdoModuleLifecycleRepository;
use N3\Module\CoreProbe\CoreProbeModule;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class BrowserInstallerTest extends TestCase
{
    private ?TablePrefixedPdo $connection = null;
    private string $prefix = '';
    private string $temporaryDirectory = '';

    protected function setUp(): void
    {
        if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
            $this->markTestSkipped('pdo_mysql is not installed.');
        }
        foreach (['N3_TEST_DB_HOST', 'N3_TEST_DB_PORT', 'N3_TEST_DB_NAME', 'N3_TEST_DB_MIGRATION_USER', 'N3_TEST_DB_MIGRATION_PASSWORD'] as $variable) {
            if (getenv($variable) === false || getenv($variable) === '') {
                $this->markTestSkipped(sprintf('%s is not configured.', $variable));
            }
        }
        $database = (string) getenv('N3_TEST_DB_NAME');
        if (!str_ends_with($database, '_test')) {
            throw new RuntimeException('Integration database names must end in _test.');
        }
        $this->prefix = 'i' . bin2hex(random_bytes(5)) . '_';
        $config = $this->databaseConfig((string) getenv('N3_TEST_DB_MIGRATION_USER'));
        $this->connection = new TablePrefixedPdo(
            $config->dsn(),
            $config->username,
            $config->password(),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false],
            $config->tableNames,
        );
        $this->connection->exec("SET time_zone = '+00:00'");
        $this->temporaryDirectory = sys_get_temp_dir() . '/n3-browser-install-' . bin2hex(random_bytes(5));
        mkdir($this->temporaryDirectory . '/storage', 0700, true);
        mkdir($this->temporaryDirectory . '/public', 0755, true);
        symlink(dirname(__DIR__, 2) . '/database', $this->temporaryDirectory . '/database');
        symlink(dirname(__DIR__, 2) . '/.htaccess', $this->temporaryDirectory . '/.htaccess');
        symlink(dirname(__DIR__, 2) . '/public/.htaccess', $this->temporaryDirectory . '/public/.htaccess');
    }

    protected function tearDown(): void
    {
        if ($this->connection instanceof TablePrefixedPdo && $this->prefix !== '') {
            $this->connection->exec('SET FOREIGN_KEY_CHECKS = 0');
            try {
                $statement = $this->connection->prepare(
                    'SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE :prefix',
                );
                $statement->execute(['prefix' => $this->prefix . '%']);
                foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $table) {
                    if (is_string($table) && str_starts_with($table, $this->prefix) && preg_match('/^[a-z0-9_]+$/D', $table) === 1) {
                        $this->connection->exec(sprintf('DROP TABLE IF EXISTS `%s`', $table));
                    }
                }
            } finally {
                $this->connection->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
        }
        @unlink($this->temporaryDirectory . '/storage/install/installed.lock');
        @rmdir($this->temporaryDirectory . '/storage/install');
        @rmdir($this->temporaryDirectory . '/storage/sessions');
        @rmdir($this->temporaryDirectory . '/storage/logs');
        @rmdir($this->temporaryDirectory . '/storage');
        @unlink($this->temporaryDirectory . '/public/.htaccess');
        @rmdir($this->temporaryDirectory . '/public');
        foreach (glob($this->temporaryDirectory . '/legacy-migrations/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->temporaryDirectory . '/legacy-migrations');
        @unlink($this->temporaryDirectory . '/database');
        @unlink($this->temporaryDirectory . '/.htaccess');
        @rmdir($this->temporaryDirectory);
    }

    public function testFreshInstallationIsResumableCreatesOneAdminAndClosesWithPrivateLock(): void
    {
        $state = new PdoInstallationStateRepository($this->connection);
        $lock = new InstallationLock($this->temporaryDirectory . '/storage/install/installed.lock');
        $service = new InstallerService(
            $this->temporaryDirectory,
            new InstallerConfig('test', 'http://example.test', str_repeat('s', 32), str_repeat('t', 32)),
            $this->databaseConfig('runtime_user'),
            $this->databaseConfig((string) getenv('N3_TEST_DB_MIGRATION_USER')),
            $this->connection,
            $this->connection,
            $state,
            new PdoUserRepository($this->connection),
            [new CoreProbeModule()],
            $lock,
        );

        self::assertSame('migrations_pending', $service->status());
        self::assertTrue($service->preflight(Request::create('GET', '/install'))->passes());
        $service->applyMigrations();
        self::assertSame('pending_admin', $service->status());
        self::assertFalse($service->adminExists());

        $service->createAdmin('Hosted Administrator', 'hosted-admin@example.test', 'a secure hosted passphrase');
        self::assertTrue($service->adminExists());
        self::assertSame('pending_admin', $service->status());
        $service->complete();

        self::assertSame('complete', $service->status());
        self::assertTrue($lock->exists());
        self::assertSame(0600, fileperms($this->temporaryDirectory . '/storage/install/installed.lock') & 0777);
        self::assertSame(1, (int) $this->connection->query("SELECT COUNT(*) FROM users WHERE role_key = 'admin'")->fetchColumn());
        self::assertSame(10, (int) $this->connection->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());

        $environment = [
            'APP_URL' => 'https://example.test',
            'SECURITY_HASH_KEY' => str_repeat('s', 32),
            'EMAIL_VERIFICATION_REQUIRED' => 'true',
            'REGISTRATION_ENABLED' => 'false',
            'INSTALL_REOPEN' => 'false',
            'INSTALL_TOKEN' => null,
            'DB_HOST' => 'localhost',
        ];
        $original = [];
        foreach ($environment as $key => $value) {
            $original[$key] = getenv($key);
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
        try {
            $modules = [new CoreProbeModule()];
            $checks = (new ProductionReadiness(
                $this->temporaryDirectory,
                ['environment' => 'production', 'debug' => false],
                $this->databaseConfig('runtime_user'),
                $this->databaseConfig((string) getenv('N3_TEST_DB_MIGRATION_USER')),
                $this->connection,
                $this->connection,
                $state,
                new ModuleLifecycleService(
                    new PdoModuleLifecycleRepository($this->connection),
                    new TransactionManager($this->connection),
                ),
                $modules,
            ))->checks();
            self::assertNotContains(false, $checks, implode(', ', array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed))));
        } finally {
            foreach ($original as $key => $value) {
                if ($value === false) {
                    putenv($key);
                    unset($_ENV[$key]);
                } else {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                }
            }
        }

        $service->complete();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('administrator already exists');
        $service->createAdmin('Second Administrator', 'second@example.test', 'another secure passphrase');
    }

    public function testExistingInstallationWithAdministratorIsClosedByForwardMigration(): void
    {
        $legacyPath = $this->temporaryDirectory . '/legacy-migrations';
        mkdir($legacyPath, 0700);
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') ?: [] as $file) {
            if (str_contains($file, '202609020010_')) {
                continue;
            }
            symlink($file, $legacyPath . '/' . basename($file));
        }
        $runner = new MigrationRunner($this->connection, $legacyPath);
        self::assertCount(9, $runner->migrate());
        (new AdminBootstrapService(
            new IdentityValidator(),
            new PdoUserRepository($this->connection),
            new TransactionManager($this->connection),
        ))->create('Existing Administrator', 'existing-admin@example.test', 'an existing secure passphrase');

        $forward = new MigrationRunner($this->connection, dirname(__DIR__, 2) . '/database/migrations');
        self::assertSame(['202609020010_extend_installation_state'], $forward->migrate());
        self::assertSame('complete', (new PdoInstallationStateRepository($this->connection))->status());
    }

    private function databaseConfig(string $username): DatabaseConfig
    {
        return new DatabaseConfig(
            (string) getenv('N3_TEST_DB_HOST'),
            (int) getenv('N3_TEST_DB_PORT'),
            (string) getenv('N3_TEST_DB_NAME'),
            $username,
            (string) getenv('N3_TEST_DB_MIGRATION_PASSWORD'),
            $this->prefix,
        );
    }
}
