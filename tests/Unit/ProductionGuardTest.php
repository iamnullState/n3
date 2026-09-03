<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Deployment\ProductionGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ProductionGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/n3-production-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/storage/install', 0700, true));
        self::assertTrue(mkdir($this->root . '/public', 0755, true));
        self::assertNotFalse(file_put_contents($this->root . '/storage/install/installed.lock', "installed\n"));
        self::assertTrue(chmod($this->root . '/storage', 0700));
        self::assertTrue(chmod($this->root . '/storage/install/installed.lock', 0600));

        foreach ($this->environmentKeys() as $key) {
            $this->original[$key] = getenv($key);
        }

        $this->setEnvironment([
            'APP_URL' => 'https://example.test',
            'SECURITY_HASH_KEY' => str_repeat('s', 32),
            'EMAIL_VERIFICATION_REQUIRED' => 'true',
            'REGISTRATION_ENABLED' => 'false',
            'INSTALL_REOPEN' => 'false',
            'INSTALL_TOKEN' => null,
            'DB_MIGRATION_USER' => null,
            'DB_MIGRATION_PASSWORD' => null,
            'DB_HOST' => 'localhost',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key]);
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        @unlink($this->root . '/storage/install/installed.lock');
        @rmdir($this->root . '/storage/install');
        @rmdir($this->root . '/storage');
        @rmdir($this->root . '/public');
        @rmdir($this->root);
    }

    public function testSafeProductionWebEnvironmentPasses(): void
    {
        $app = ['environment' => 'production', 'debug' => false];

        self::assertSame([], ProductionGuard::violations($this->root, $app, false));
        ProductionGuard::assertWebReady($this->root, $app);
        self::addToAssertionCount(1);
    }

    public function testNonProductionWebEnvironmentIsNotGuarded(): void
    {
        $this->setEnvironment(['SECURITY_HASH_KEY' => null]);

        ProductionGuard::assertWebReady($this->root, ['environment' => 'test', 'debug' => true]);
        self::addToAssertionCount(1);
    }

    public function testMigrationCredentialsAreAllowedOnlyForCliPreflight(): void
    {
        $this->setEnvironment([
            'DB_MIGRATION_USER' => 'n3_migrator',
            'DB_MIGRATION_PASSWORD' => 'private',
        ]);
        $app = ['environment' => 'production', 'debug' => false];

        self::assertContains(
            'migration credentials must not be available to the web runtime',
            ProductionGuard::violations($this->root, $app, false),
        );
        self::assertSame([], ProductionGuard::violations($this->root, $app, true));
    }

    public function testUnsafeProductionSettingsFailClosedWithoutSecretValues(): void
    {
        $secret = 'do-not-expose-this-installer-token';
        $this->setEnvironment([
            'APP_URL' => 'https://example.test?redirect=unsafe',
            'SECURITY_HASH_KEY' => 'replace-with-key',
            'EMAIL_VERIFICATION_REQUIRED' => 'false',
            'REGISTRATION_ENABLED' => 'invalid',
            'INSTALL_REOPEN' => 'true',
            'INSTALL_TOKEN' => $secret,
            'DB_MIGRATION_USER' => 'n3_migrator',
            'DB_HOST' => 'database.example.test',
        ]);

        try {
            ProductionGuard::assertWebReady($this->root, ['environment' => 'production', 'debug' => true]);
            self::fail('Unsafe production configuration should fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('APP_URL must use HTTPS', $exception->getMessage());
            self::assertStringContainsString('REGISTRATION_ENABLED must remain false', $exception->getMessage());
            self::assertStringContainsString('remote database hosts require', $exception->getMessage());
            self::assertStringNotContainsString($secret, $exception->getMessage());
        }
    }

    public function testPrivateLockAndStoragePermissionsAreRequired(): void
    {
        self::assertTrue(chmod($this->root . '/storage', 0707));
        self::assertTrue(chmod($this->root . '/storage/install/installed.lock', 0644));

        $violations = ProductionGuard::violations(
            $this->root,
            ['environment' => 'production', 'debug' => false],
            false,
        );

        self::assertContains('private storage must not be accessible to other system users', $violations);
        self::assertContains('private installation lock is missing or too permissive', $violations);
    }

    public function testApacheDeploymentFilesProtectRootAndRoutePublicRequests(): void
    {
        $project = dirname(__DIR__, 2);
        $rootRules = (string) file_get_contents($project . '/.htaccess');
        $publicRules = (string) file_get_contents($project . '/public/.htaccess');

        self::assertStringContainsString('Require all denied', $rootRules);
        self::assertStringContainsString('Options -Indexes', $publicRules);
        self::assertStringContainsString('RewriteRule ^ index.php [L]', $publicRules);
        self::assertStringContainsString('REQUEST_FILENAME', $publicRules);
    }

    /** @return list<string> */
    private function environmentKeys(): array
    {
        return [
            'APP_URL',
            'SECURITY_HASH_KEY',
            'EMAIL_VERIFICATION_REQUIRED',
            'REGISTRATION_ENABLED',
            'INSTALL_REOPEN',
            'INSTALL_TOKEN',
            'DB_MIGRATION_USER',
            'DB_MIGRATION_PASSWORD',
            'DB_HOST',
        ];
    }

    /** @param array<string, string|null> $values */
    private function setEnvironment(array $values): void
    {
        foreach ($values as $key => $value) {
            if ($value === null) {
                putenv($key);
                unset($_ENV[$key]);
                continue;
            }
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}
