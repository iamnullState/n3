<?php

declare(strict_types=1);

namespace N3\App\Install;

use RuntimeException;

final readonly class InstallationLock
{
    public function __construct(private string $path)
    {
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    public function create(): void
    {
        if ($this->exists()) {
            return;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the private installer directory.');
        }
        chmod($directory, 0700);
        $handle = @fopen($this->path, 'x');
        if ($handle === false) {
            if ($this->exists()) {
                return;
            }
            throw new RuntimeException('Unable to create the private installation lock.');
        }
        try {
            if (fwrite($handle, "installed\n") === false) {
                throw new RuntimeException('Unable to write the private installation lock.');
            }
        } finally {
            fclose($handle);
        }
        chmod($this->path, 0600);
    }
}
