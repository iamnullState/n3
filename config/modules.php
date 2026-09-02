<?php

declare(strict_types=1);

use N3\Core\Config\Environment;
use N3\Core\Module\Module;
use N3\Module\Analytics\AnalyticsModule;
use N3\Module\Blog\BlogModule;
use N3\Module\CoreProbe\CoreProbeModule;
use N3\Module\McpServer\McpServerModule;
use N3\Module\Media\MediaModule;

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

if (Environment::boolean('MEDIA_ENABLED', false)) {
    $modules[] = new MediaModule();
}

if (Environment::boolean('BLOG_ENABLED', false)) {
    $modules[] = new BlogModule();
}

return $modules;
