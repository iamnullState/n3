<?php

declare(strict_types=1);

use N3\Core\Config\Environment;
use N3\Core\Module\Module;
use N3\Module\Analytics\AnalyticsModule;
use N3\Module\CoreProbe\CoreProbeModule;
use N3\Module\McpServer\McpServerModule;

/** @var list<Module> */
$modules = [
    new CoreProbeModule(),
];

if (Environment::boolean('ANALYTICS_ENABLED', false)) {
    $modules[] = new AnalyticsModule();
}

if (Environment::boolean('MCP_ENABLED', false)) {
    $modules[] = new McpServerModule();
}

return $modules;
