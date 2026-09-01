<?php

declare(strict_types=1);

namespace N3\Core\Storage;

use N3\Core\Module\ModuleResourcePolicy;
use RuntimeException;

final class ScopedModuleStorage
{
    private const MAX_BYTES = 1048576;

    private readonly string $root;
    private readonly string $baseRoot;

    public function __construct(string $baseRoot, string $moduleId, string $area = 'data')
    {
        if (!str_starts_with($baseRoot, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Module storage roots must be absolute paths.');
        }
        if (!in_array($area, ['cache', 'config', 'data'], true)) {
            throw new \InvalidArgumentException('Module storage areas must be cache, config, or data.');
        }

        [$vendor, $name] = ModuleResourcePolicy::segments($moduleId);
        $this->baseRoot = rtrim($baseRoot, DIRECTORY_SEPARATOR);
        $this->root = $this->baseRoot
            . DIRECTORY_SEPARATOR . $vendor
            . DIRECTORY_SEPARATOR . $name
            . DIRECTORY_SEPARATOR . $area;
    }

    public function put(string $relativePath, string $contents): void
    {
        if (strlen($contents) > self::MAX_BYTES) {
            throw new RuntimeException('Module runtime files cannot exceed 1 MiB.');
        }

        $path = $this->path($relativePath);
        $directory = dirname($path);
        $this->ensureDirectory($directory);
        $this->assertNoLinks($directory);

        if (is_link($path)) {
            throw new RuntimeException('Module storage refuses symbolic-link targets.');
        }

        $temporary = tempnam($directory, '.n3-');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create a private temporary module file.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to write a private module file.');
            }
            if (!rename($temporary, $path)) {
                throw new RuntimeException('Unable to atomically publish a private module file.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function read(string $relativePath): ?string
    {
        $path = $this->path($relativePath);
        if (!file_exists($path)) {
            return null;
        }
        $this->assertNoLinks(dirname($path));
        if (is_link($path) || !is_file($path)) {
            throw new RuntimeException('Module storage refuses non-regular files.');
        }
        $size = filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new RuntimeException('Module runtime file size is invalid.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read a private module file.');
        }

        return $contents;
    }

    public function exists(string $relativePath): bool
    {
        return $this->read($relativePath) !== null;
    }

    private function path(string $relativePath): string
    {
        return $this->root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, ModuleResourcePolicy::relativeSegments($relativePath));
    }

    private function ensureDirectory(string $directory): void
    {
        $this->assertNoLinks($directory);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create private module storage.');
        }
        if (!chmod($directory, 0700)) {
            throw new RuntimeException('Unable to restrict private module storage permissions.');
        }
    }

    private function assertNoLinks(string $directory): void
    {
        $current = $directory;
        while (true) {
            if (is_link($current)) {
                throw new RuntimeException('Module storage refuses symbolic-link paths.');
            }
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
    }
}
