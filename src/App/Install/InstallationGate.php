<?php

declare(strict_types=1);

namespace N3\App\Install;

use N3\Core\Config\Environment;
use N3\Core\Database\ConnectionFactory;
use N3\Core\Http\Request;
use Throwable;

final readonly class InstallationGate
{
    public function __construct(private string $root, private InstallationLock $lock)
    {
    }

    public function shouldHandle(Request $request): bool
    {
        $installRoute = $request->path === '/install' || str_starts_with($request->path, '/install/');
        try {
            if ($installRoute && Environment::boolean('INSTALL_REOPEN', false)) {
                return true;
            }
        } catch (Throwable) {
            return true;
        }
        if ($this->lock->exists()) {
            return false;
        }

        try {
            $database = require $this->root . '/config/database.php';
            $connection = (new ConnectionFactory())->create($database);
            if ((new PdoInstallationStateRepository($connection))->isComplete()) {
                $this->lock->create();
                return false;
            }
        } catch (Throwable) {
            // An unreachable or incomplete database is handled by the isolated installer.
        }

        return true;
    }
}
