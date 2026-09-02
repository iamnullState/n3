<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\App\Content\PageMediaProvider;
use N3\Core\Event\EventDispatcher;
use N3\Core\Http\Request;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Module\ModuleManager;
use N3\Core\Security\CurrentPrincipal;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Service\ServiceRegistry;
use N3\Core\View\View;
use N3\Module\Media\GdImageProcessor;
use N3\Module\Media\MediaService;
use N3\Module\Media\MediaModule;
use N3\Module\Media\MediaSchema;
use PHPUnit\Framework\TestCase;

final class MediaModuleTest extends TestCase
{
    public function testModuleOwnsItsForwardOnlyMediaMigration(): void
    {
        $module = new MediaModule();
        self::assertSame(MediaSchema::MODULE_ID, $module->manifest()->id);
        self::assertSame('0.2.0', $module->manifest()->version);
        self::assertInstanceOf(ModuleMigrationProvider::class, $module);
        self::assertSame(MediaSchema::MODULE_ID, $module->migrations()[0]->moduleId());
        self::assertSame('202609010002_create_media_library', $module->migrations()[0]->version());
        self::assertSame('202609010003_create_page_attachments', $module->migrations()[1]->version());
    }

    public function testMediaIsDisabledByDefaultAndCanBeExplicitlyEnabled(): void
    {
        $previous = getenv('MEDIA_ENABLED');
        $present = array_key_exists('MEDIA_ENABLED', $_ENV);
        $previousEnv = $_ENV['MEDIA_ENABLED'] ?? null;
        unset($_ENV['MEDIA_ENABLED']);
        putenv('MEDIA_ENABLED');
        try {
            $disabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertNotContains(MediaSchema::MODULE_ID, array_map(static fn ($module): string => $module->manifest()->id, $disabled));

            putenv('MEDIA_ENABLED=true');
            $enabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertContains(MediaSchema::MODULE_ID, array_map(static fn ($module): string => $module->manifest()->id, $enabled));
        } finally {
            $previous === false ? putenv('MEDIA_ENABLED') : putenv('MEDIA_ENABLED=' . $previous);
            if ($present) {
                $_ENV['MEDIA_ENABLED'] = $previousEnv;
            } else {
                unset($_ENV['MEDIA_ENABLED']);
            }
        }
    }

    public function testEnabledModuleRegistersItsServiceAndPrivateRoutes(): void
    {
        if (!GdImageProcessor::available()) {
            $this->markTestSkipped('GD with JPEG, PNG, and WebP support is not installed.');
        }
        $previous = getenv('SECURITY_HASH_KEY');
        $present = array_key_exists('SECURITY_HASH_KEY', $_ENV);
        $previousEnv = $_ENV['SECURITY_HASH_KEY'] ?? null;
        unset($_ENV['SECURITY_HASH_KEY']);
        putenv('SECURITY_HASH_KEY=' . str_repeat('k', 32));
        $log = tempnam(sys_get_temp_dir(), 'n3-media-module-');
        self::assertNotFalse($log);
        try {
            $services = new ServiceRegistry();
            $router = new Router();
            $services->register(Router::class, $router);
            $services->register(View::class, new View(dirname(__DIR__, 2) . '/resources/views'));
            $services->register(FileLogger::class, new FileLogger($log));
            $services->register(CurrentPrincipalProvider::class, new class implements CurrentPrincipalProvider {
                public function current(): ?CurrentPrincipal { return null; }
            });
            (new ModuleManager('0.2.0', $services, new EventDispatcher()))->boot([new MediaModule()]);

            self::assertTrue($services->has(MediaService::class));
            self::assertTrue($services->has(PageMediaProvider::class));
            $response = $router->dispatch(Request::create('GET', '/admin/media'));
            self::assertSame(303, $response->status());
            self::assertSame('/login', $response->headers()['Location']);
            self::assertSame('no-store', $response->headers()['Cache-Control']);
        } finally {
            unlink($log);
            $previous === false ? putenv('SECURITY_HASH_KEY') : putenv('SECURITY_HASH_KEY=' . $previous);
            if ($present) {
                $_ENV['SECURITY_HASH_KEY'] = $previousEnv;
            } else {
                unset($_ENV['SECURITY_HASH_KEY']);
            }
        }
    }
}
