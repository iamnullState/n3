<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Core\Event\EventDispatcher;
use N3\Core\Module\ModuleManager;
use N3\Core\Service\ServiceRegistry;
use N3\Module\McpServer\McpServer;
use N3\Module\McpServer\McpServerModule;
use PHPUnit\Framework\TestCase;

final class McpServerModuleTest extends TestCase
{
    public function testModuleRegistersTheBoundedServerWithoutMigrations(): void
    {
        $services = new ServiceRegistry();
        $module = new McpServerModule();

        (new ModuleManager('0.2.0', $services, new EventDispatcher()))->boot([$module]);

        self::assertSame(McpServerModule::MODULE_ID, $module->manifest()->id);
        self::assertSame(McpServer::SERVER_VERSION, $module->manifest()->version);
        self::assertInstanceOf(McpServer::class, $services->get(McpServer::class));
    }

    public function testMcpIsDisabledByDefaultAndRequiresExplicitEnablement(): void
    {
        $previous = getenv('MCP_ENABLED');
        $previousEnv = $_ENV['MCP_ENABLED'] ?? null;
        unset($_ENV['MCP_ENABLED']);
        putenv('MCP_ENABLED');

        try {
            $disabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertNotContains(McpServerModule::MODULE_ID, array_map(
                static fn ($module): string => $module->manifest()->id,
                $disabled,
            ));

            putenv('MCP_ENABLED=true');
            $enabled = require dirname(__DIR__, 2) . '/config/modules.php';
            self::assertContains(McpServerModule::MODULE_ID, array_map(
                static fn ($module): string => $module->manifest()->id,
                $enabled,
            ));
        } finally {
            if ($previous === false) {
                putenv('MCP_ENABLED');
            } else {
                putenv('MCP_ENABLED=' . $previous);
            }
            if ($previousEnv === null) {
                unset($_ENV['MCP_ENABLED']);
            } else {
                $_ENV['MCP_ENABLED'] = $previousEnv;
            }
        }
    }
}
