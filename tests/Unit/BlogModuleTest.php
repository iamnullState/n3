<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Event\EventDispatcher;
use N3\Core\Http\Request;
use N3\Core\Http\Router;
use N3\Core\Logging\FileLogger;
use N3\Core\Module\ModuleManager;
use N3\Core\Module\ModuleMigrationProvider;
use N3\Core\Security\CurrentActor;
use N3\Core\Security\CurrentActorProvider;
use N3\Core\Security\LazyCurrentActorProvider;
use N3\Core\Service\ServiceRegistry;
use N3\Core\View\View;
use N3\Module\Blog\BlogModule;
use N3\Module\Blog\BlogSchema;
use N3\Module\Blog\BlogService;
use PHPUnit\Framework\TestCase;

final class BlogModuleTest extends TestCase
{
    public function testModuleOwnsItsForwardMigrationAndRegistersLazyRoutes(): void
    {
        $module = new BlogModule();
        self::assertSame(BlogSchema::MODULE_ID, $module->manifest()->id);
        self::assertSame('0.1.0', $module->manifest()->version);
        self::assertInstanceOf(ModuleMigrationProvider::class, $module);
        self::assertSame(BlogSchema::MODULE_ID, $module->migrations()[0]->moduleId());
        self::assertSame('202609020001_create_blog_content', $module->migrations()[0]->version());

        $services = new ServiceRegistry();
        $router = new Router();
        $log = tempnam(sys_get_temp_dir(), 'n3-blog-module-');
        self::assertNotFalse($log);
        $services->register(Router::class, $router);
        $services->register(View::class, new View(dirname(__DIR__, 2) . '/resources/views'));
        $services->register(FileLogger::class, new FileLogger($log));
        $services->register(CurrentActorProvider::class, new class implements CurrentActorProvider {
            public function current(): ?CurrentActor { return null; }
        });
        try {
            (new ModuleManager('0.2.0', $services, new EventDispatcher()))->boot([$module]);
            self::assertTrue($services->has(BlogService::class));
            $admin = $router->dispatch(Request::create('GET', '/admin/blog'));
            self::assertSame(303, $admin->status());
            self::assertSame('/login', $admin->headers()['Location']);
            self::assertSame('no-store', $admin->headers()['Cache-Control']);
            self::assertSame(503, $router->dispatch(Request::create('GET', '/blog'))->status());
            $logged = (string) file_get_contents($log);
            self::assertStringContainsString('blog_public_list_failed', $logged);
            self::assertStringNotContainsString('DB_PASSWORD', $logged);
            self::assertStringNotContainsString('/blog/', $logged);
        } finally {
            unlink($log);
        }
    }

    public function testBlogIsDisabledByDefaultAndCanBeExplicitlyEnabled(): void
    {
        $previous = getenv('BLOG_ENABLED');
        $present = array_key_exists('BLOG_ENABLED', $_ENV);
        $previousEnv = $_ENV['BLOG_ENABLED'] ?? null;
        unset($_ENV['BLOG_ENABLED']);
        putenv('BLOG_ENABLED');
        try {
            $disabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertNotContains(BlogSchema::MODULE_ID, array_map(static fn ($module): string => $module->manifest()->id, $disabled));
            putenv('BLOG_ENABLED=true');
            $enabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertContains(BlogSchema::MODULE_ID, array_map(static fn ($module): string => $module->manifest()->id, $enabled));
        } finally {
            $previous === false ? putenv('BLOG_ENABLED') : putenv('BLOG_ENABLED=' . $previous);
            if ($present) { $_ENV['BLOG_ENABLED'] = $previousEnv; } else { unset($_ENV['BLOG_ENABLED']); }
        }
    }

    public function testActorIdentifierMustBePositive(): void
    {
        self::assertSame(42, (new CurrentActor(42, 'admin'))->id);
        $this->expectException(\InvalidArgumentException::class);
        new CurrentActor(0, 'admin');
    }

    public function testActorProviderIsCreatedOnlyWhenRequested(): void
    {
        $calls = 0;
        $provider = new LazyCurrentActorProvider(static function () use (&$calls): CurrentActorProvider {
            ++$calls;

            return new class implements CurrentActorProvider {
                public function current(): ?CurrentActor { return new CurrentActor(7, 'admin'); }
            };
        });

        self::assertSame(0, $calls);
        self::assertSame(7, $provider->current()?->id);
        self::assertSame('admin', $provider->current()?->authority);
        self::assertSame(1, $calls);
    }
}
