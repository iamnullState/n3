<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Install\InstallerConfig;
use N3\App\Install\InstallerAttemptLimiter;
use N3\App\Install\InstallationGate;
use N3\App\Install\InstallationLock;
use N3\Core\Http\Request;
use N3\Core\Session\ArraySessionStore;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstallerConfigTest extends TestCase
{
    public function testIndependentTokenAuthorizationIsExactAndSecretsAreNotPublicProperties(): void
    {
        $config = new InstallerConfig('test', 'http://example.test', str_repeat('s', 32), str_repeat('t', 32));

        self::assertTrue($config->authorizes(str_repeat('t', 32)));
        self::assertFalse($config->authorizes(str_repeat('x', 32)));
        self::assertFalse($config->authorizes(null));
        self::assertFalse(property_exists($config, 'publicInstallToken'));
    }

    public function testPlaceholderSecurityKeyFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SECURITY_HASH_KEY');

        new InstallerConfig('test', 'http://example.test', 'replace-with-at-least-32-random-bytes', str_repeat('t', 32));
    }

    public function testPlaceholderOrShortInstallerTokenFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INSTALL_TOKEN');

        new InstallerConfig('test', 'http://example.test', str_repeat('s', 32), 'too-short');
    }

    public function testSessionLimiterUsesAFixedWindowAndCanBeCleared(): void
    {
        $session = new ArraySessionStore();
        $limiter = new InstallerAttemptLimiter($session, 2, 60);

        self::assertTrue($limiter->allows('authorize', 100));
        self::assertTrue($limiter->allows('authorize', 101));
        self::assertFalse($limiter->allows('authorize', 102));
        self::assertTrue($limiter->allows('authorize', 161));
        $limiter->clear('authorize');
        self::assertTrue($limiter->allows('authorize', 162));
    }

    public function testPrivateLockClosesInstallerAndExplicitReopenOnlyExposesInstallRoutes(): void
    {
        $directory = sys_get_temp_dir() . '/n3-install-gate-' . bin2hex(random_bytes(5));
        $lock = new InstallationLock($directory . '/installed.lock');
        $lock->create();
        $previous = $_ENV['INSTALL_REOPEN'] ?? null;
        try {
            unset($_ENV['INSTALL_REOPEN']);
            self::assertFalse((new InstallationGate('/missing-root', $lock))->shouldHandle(Request::create('GET', '/install')));
            $_ENV['INSTALL_REOPEN'] = 'true';
            self::assertTrue((new InstallationGate('/missing-root', $lock))->shouldHandle(Request::create('GET', '/install')));
            self::assertFalse((new InstallationGate('/missing-root', $lock))->shouldHandle(Request::create('GET', '/')));
            self::assertSame(0600, fileperms($directory . '/installed.lock') & 0777);
        } finally {
            if ($previous === null) { unset($_ENV['INSTALL_REOPEN']); } else { $_ENV['INSTALL_REOPEN'] = $previous; }
            @unlink($directory . '/installed.lock');
            @rmdir($directory);
        }
    }
}
