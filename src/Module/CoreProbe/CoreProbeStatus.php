<?php

declare(strict_types=1);

namespace N3\Module\CoreProbe;

final class CoreProbeStatus
{
    public bool $booted = false;

    public bool $observedCoreStart = false;
}
