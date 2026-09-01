<?php

declare(strict_types=1);

namespace N3\Core\Module;

use ReflectionClass;
use RuntimeException;

final class ModuleMigrationCatalog
{
    /**
     * @param list<Module> $modules Dependency-ordered enabled modules.
     * @return list<ModuleMigrationDefinition>
     */
    public function definitions(array $modules): array
    {
        $definitions = [];

        foreach ($modules as $module) {
            if (!$module instanceof ModuleMigrationProvider) {
                continue;
            }

            $manifest = $module->manifest();
            $moduleFile = $this->sourceFile($module, $manifest->id, 'module');
            $moduleRoot = dirname($moduleFile);
            $versions = [];
            $owned = [];

            foreach ($module->migrations() as $migration) {
                if (!$migration instanceof ModuleMigration) {
                    throw new RuntimeException(sprintf(
                        'Module "%s" returned an invalid migration definition.',
                        $manifest->id,
                    ));
                }
                if ($migration->moduleId() !== $manifest->id) {
                    throw new RuntimeException(sprintf(
                        'Module migration "%s" does not belong to "%s".',
                        $migration->version(),
                        $manifest->id,
                    ));
                }
                $version = $migration->version();
                if (!preg_match('/^[0-9]{12}_[a-z][a-z0-9_]{0,79}$/D', $version)) {
                    throw new RuntimeException(sprintf(
                        'Module "%s" has an invalid migration version.',
                        $manifest->id,
                    ));
                }
                if (isset($versions[$version])) {
                    throw new RuntimeException(sprintf(
                        'Module "%s" has duplicate migration version "%s".',
                        $manifest->id,
                        $version,
                    ));
                }

                $file = $this->sourceFile($migration, $manifest->id, $version);
                if ($file !== $moduleFile && !str_starts_with($file, $moduleRoot . DIRECTORY_SEPARATOR)) {
                    throw new RuntimeException(sprintf(
                        'Module migration "%s:%s" must live within its module source directory.',
                        $manifest->id,
                        $version,
                    ));
                }
                $checksum = hash_file('sha256', $file);
                if ($checksum === false) {
                    throw new RuntimeException(sprintf(
                        'Module migration "%s:%s" could not be checksummed.',
                        $manifest->id,
                        $version,
                    ));
                }

                $versions[$version] = true;
                $owned[$version] = new ModuleMigrationDefinition(
                    $manifest->id,
                    $version,
                    $checksum,
                    $migration,
                );
            }

            ksort($owned, SORT_STRING);
            array_push($definitions, ...array_values($owned));
        }

        return $definitions;
    }

    private function sourceFile(object $definition, string $moduleId, string $identifier): string
    {
        $reflection = new ReflectionClass($definition);
        $file = $reflection->getFileName();
        if ($reflection->isAnonymous() || $file === false || ($real = realpath($file)) === false || !is_readable($real)) {
            throw new RuntimeException(sprintf(
                'Module migration source "%s:%s" must be a readable named class.',
                $moduleId,
                $identifier,
            ));
        }

        return $real;
    }
}
