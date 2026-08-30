<?php

declare(strict_types=1);

namespace N3\Module\CoreProbe;

use N3\Core\Event\CoreStarted;
use LogicException;
use N3\Core\Event\EventListenerRegistry;
use N3\Core\Module\Module;
use N3\Core\Module\ModuleManifest;
use N3\Core\Service\ServiceRegistry;

final class CoreProbeModule implements Module
{
    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(
            id: 'n3/core-probe',
            version: '0.1.0',
            coreConstraint: '^0.2',
        );
    }

    public function register(ServiceRegistry $services): void
    {
        $services->register(CoreProbeStatus::class, new CoreProbeStatus());
    }

    public function boot(ServiceRegistry $services, EventListenerRegistry $events): void
    {
        $status = $services->get(CoreProbeStatus::class);

        if (!$status instanceof CoreProbeStatus) {
            throw new LogicException('The Core Probe status service does not satisfy its declared contract.');
        }

        $status->booted = true;
        $events->listen(
            CoreStarted::class,
            $this->manifest()->id,
            'observe-core-start',
            static function (CoreStarted $event) use ($status): void {
                $status->observedCoreStart = $event->coreVersion !== '';
            },
        );
    }
}
