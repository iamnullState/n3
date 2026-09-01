<?php

declare(strict_types=1);

use N3\Core\Job\JobHandler;
use N3\Module\CoreProbe\CoreProbeJobHandler;

/** @var list<JobHandler> */
return [
    new CoreProbeJobHandler(),
];
