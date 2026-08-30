<?php

declare(strict_types=1);

namespace N3\Core\Module;

use N3\Core\Event\EventListenerRegistry;
use N3\Core\Service\ServiceRegistry;

interface Module
{
    public function manifest(): ModuleManifest;

    public function register(ServiceRegistry $services): void;

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void;
}
