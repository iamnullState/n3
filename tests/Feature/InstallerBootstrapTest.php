<?php

declare(strict_types=1);

namespace N3\Tests\Feature;

use N3\App\Install\InstallationLock;
use N3\App\Install\InstallerKernel;
use N3\Core\Http\Request;
use PHPUnit\Framework\TestCase;

final class InstallerBootstrapTest extends TestCase
{
    public function testIncompleteConfigurationUsesNeutralIsolatedSetupResponse(): void
    {
        $this->withInvalidInstallerConfiguration(function (): void {
            $request = Request::create('GET', '/install');
            $response = InstallerKernel::application(
                dirname(__DIR__, 2),
                $request,
                new InstallationLock(sys_get_temp_dir() . '/n3-unused-install-lock/installed.lock'),
            )->handle($request);

            self::assertSame(503, $response->status());
            self::assertStringContainsString('Setup is not ready', $response->body());
            self::assertStringNotContainsString('replace-with-at-least-32-random-bytes', $response->body());
            self::assertStringNotContainsString('N3', $response->body());
            self::assertSame('no-store, private', $response->headers()['Cache-Control']);
            self::assertArrayHasKey('Content-Security-Policy', $response->headers());
        });
    }

    public function testIncompleteNormalRequestRedirectsToInstallerWithoutBootingCms(): void
    {
        $this->withInvalidInstallerConfiguration(function (): void {
            $request = Request::create('GET', '/admin/pages');
            $response = InstallerKernel::application(dirname(__DIR__, 2), $request)->handle($request);

            self::assertSame(303, $response->status());
            self::assertSame('/install', $response->headers()['Location']);
        });
    }

    private function withInvalidInstallerConfiguration(callable $test): void
    {
        $previousKey = $_ENV['SECURITY_HASH_KEY'] ?? null;
        $previousToken = $_ENV['INSTALL_TOKEN'] ?? null;
        try {
            $_ENV['SECURITY_HASH_KEY'] = 'replace-with-at-least-32-random-bytes';
            unset($_ENV['INSTALL_TOKEN']);
            $test();
        } finally {
            if ($previousKey === null) {
                unset($_ENV['SECURITY_HASH_KEY']);
            } else {
                $_ENV['SECURITY_HASH_KEY'] = $previousKey;
            }
            if ($previousToken === null) {
                unset($_ENV['INSTALL_TOKEN']);
            } else {
                $_ENV['INSTALL_TOKEN'] = $previousToken;
            }
        }
    }
}
