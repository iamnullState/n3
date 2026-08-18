<?php
declare(strict_types=1);

namespace N3\Service;

use ZipArchive;

final class PluginArchiveInstaller
{
    public function __construct(private readonly string $pluginDirectory) {}

    public function install(array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($upload['tmp_name'] ?? ''))) {
            throw new DomainException('Choose a plugin ZIP archive.', 422);
        }
        if ((int)($upload['size'] ?? 0) > 20 * 1024 * 1024) throw new DomainException('Plugin ZIP archives must be 20 MB or smaller.', 413);
        $zip = new ZipArchive();
        if ($zip->open((string)$upload['tmp_name']) !== true) throw new DomainException('The uploaded file is not a readable ZIP archive.', 422);
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 500) throw new DomainException('Plugin archives may contain between 1 and 500 files.', 422);
            [$pluginId, $prefix] = $this->archiveRoot($zip, (string)($upload['name'] ?? 'plugin.zip'));
            $target = rtrim($this->pluginDirectory, '/') . '/' . $pluginId;
            if (file_exists($target)) throw new DomainException('A plugin with this ID is already installed. Remove or rename it before uploading a replacement.', 409);
            if (!is_dir($this->pluginDirectory) && !mkdir($this->pluginDirectory, 0775, true) && !is_dir($this->pluginDirectory)) {
                throw new \RuntimeException('Could not create plugin storage.');
            }
            $temporary = rtrim($this->pluginDirectory, '/') . '/.' . $pluginId . '-' . bin2hex(random_bytes(6));
            if (!mkdir($temporary, 0770)) throw new \RuntimeException('Could not prepare plugin installation.');
            try {
                $total = 0;
                for ($index = 0; $index < $zip->numFiles; $index++) {
                    $stat = $zip->statIndex($index);
                    $name = is_array($stat) ? str_replace('\\', '/', (string)($stat['name'] ?? '')) : '';
                    if ($name === '' || str_ends_with($name, '/')) continue;
                    if ($prefix !== '' && !str_starts_with($name, $prefix)) continue;
                    $relative = $prefix === '' ? $name : substr($name, strlen($prefix));
                    if (!$this->safeRelativePath($relative)) throw new DomainException('The plugin ZIP contains an unsafe file path.', 422);
                    $size = (int)($stat['size'] ?? 0);
                    $total += $size;
                    if ($size > 5 * 1024 * 1024 || $total > 30 * 1024 * 1024) throw new DomainException('The uncompressed plugin is too large.', 413);
                    $destination = $temporary . '/' . $relative;
                    $directory = dirname($destination);
                    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) throw new \RuntimeException('Could not prepare a plugin directory.');
                    $source = $zip->getStream($name);
                    if (!is_resource($source)) throw new DomainException('A plugin file could not be read.', 422);
                    $output = fopen($destination, 'xb');
                    if (!is_resource($output)) { fclose($source); throw new \RuntimeException('Could not write a plugin file.'); }
                    stream_copy_to_stream($source, $output, 5 * 1024 * 1024 + 1);
                    fclose($source);
                    fclose($output);
                    chmod($destination, 0640);
                }
                if (!is_file($temporary . '/plugin.json')) throw new DomainException('The plugin ZIP must contain plugin.json at its root.', 422);
                $manifest = json_decode((string)file_get_contents($temporary . '/plugin.json'), true);
                if (!is_array($manifest)) throw new DomainException('The plugin manifest is not valid JSON.', 422);
                if (!rename($temporary, $target)) throw new \RuntimeException('Could not finish installing the plugin.');
            } catch (\Throwable $error) {
                $this->removeDirectory($temporary);
                throw $error;
            }
            return $pluginId;
        } finally {
            $zip->close();
        }
    }

    private function archiveRoot(ZipArchive $zip, string $uploadName): array
    {
        $files = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = str_replace('\\', '/', (string)$zip->getNameIndex($index));
            if ($name !== '' && !str_ends_with($name, '/') && !str_starts_with($name, '__MACOSX/')) $files[] = $name;
        }
        if (in_array('plugin.json', $files, true)) {
            $id = strtolower((string)pathinfo($uploadName, PATHINFO_FILENAME));
            $id = trim((string)preg_replace('/[^a-z0-9_-]+/', '-', $id), '-');
            if (!$this->validId($id)) throw new DomainException('Name the ZIP with a valid plugin ID, such as my-plugin.zip.', 422);
            return [$id, ''];
        }
        $roots = [];
        foreach ($files as $file) {
            $parts = explode('/', $file, 2);
            if (count($parts) === 2) $roots[$parts[0]] = true;
        }
        $candidates = array_values(array_filter(array_keys($roots), fn(string $root): bool => in_array($root . '/plugin.json', $files, true)));
        if (count($candidates) !== 1 || !$this->validId($candidates[0])) {
            throw new DomainException('The ZIP must contain one plugin folder with plugin.json at its root.', 422);
        }
        return [$candidates[0], $candidates[0] . '/'];
    }

    private function validId(string $id): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $id) === 1;
    }

    private function safeRelativePath(string $path): bool
    {
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) return false;
        foreach (explode('/', $path) as $part) if ($part === '' || $part === '.' || $part === '..') return false;
        return mb_strlen($path) <= 500;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        @rmdir($directory);
    }
}
