<?php

declare(strict_types=1);

namespace N3\Module\CoreProbe;

use N3\Core\Job\JobHandler;

final class CoreProbeJobHandler implements JobHandler
{
    public function moduleId(): string
    {
        return 'n3/core-probe';
    }

    public function type(): string
    {
        return 'probe';
    }

    public function handle(array $payload): void
    {
        // This contract probe deliberately has no side effects.
    }
}
