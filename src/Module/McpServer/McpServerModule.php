<?php

declare(strict_types=1);

namespace N3\Module\McpServer;

use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleManifest;
use N3\Core\Service\ServiceRegistry;

final class McpServerModule implements Module
{
    public const MODULE_ID = 'n3/mcp-server';

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::MODULE_ID, McpServer::SERVER_VERSION, '^0.2');
    }

    public function register(ServiceRegistry $services): void
    {
        $services->register(McpServer::class, McpServer::createDefault());
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
    }
}
