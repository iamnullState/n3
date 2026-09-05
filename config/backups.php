<?php

declare(strict_types=1);

use N3\Core\Backup\BackupConfig;

return BackupConfig::fromEnvironment(dirname(__DIR__));
