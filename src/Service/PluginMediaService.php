<?php
declare(strict_types=1);

namespace N3\Service;

final class PluginMediaService
{
    public const MAX_BYTES = 262_144_000;

    private const TYPES = [
        'image/jpeg' => ['jpg', 'image'],
        'image/png' => ['png', 'image'],
        'image/gif' => ['gif', 'image'],
        'image/webp' => ['webp', 'image'],
        'image/avif' => ['avif', 'image'],
        'image/bmp' => ['bmp', 'image'],
        'video/mp4' => ['mp4', 'video'],
        'video/webm' => ['webm', 'video'],
        'video/quicktime' => ['mov', 'video'],
    ];

    public function __construct(private readonly string $dataDir) {}

    public function store(string $pluginId, array $upload): array
    {
        $this->assertPluginId($pluginId);
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The media file is larger than the 250 MB limit.'
                : 'The media upload did not complete.';
            throw new DomainException($message, 422);
        }
        $temporary = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($temporary === '' || !is_uploaded_file($temporary) || $size < 1) {
            throw new DomainException('Choose a non-empty media file.', 422);
        }
        if ($size > self::MAX_BYTES) throw new DomainException('The media file is larger than the 250 MB limit.', 422);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
        if (!isset(self::TYPES[$mime])) {
            throw new DomainException('Use JPEG, PNG, GIF, WebP, AVIF, BMP, MP4, WebM, or MOV media.', 422);
        }
        [$extension, $kind] = self::TYPES[$mime];
        $directory = $this->directory($pluginId);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the plugin media directory.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($temporary, $target)) throw new \RuntimeException('Could not store the uploaded media.');
        chmod($target, 0660);
        try {
            $this->stripMetadata($target);
            clearstatcache(true, $target);
            $storedMime = (new \finfo(FILEINFO_MIME_TYPE))->file($target) ?: '';
            if ($storedMime !== $mime || !is_file($target) || (int)filesize($target) < 1) {
                throw new \RuntimeException('Sanitized media did not pass content inspection.');
            }
        } catch (\Throwable $error) {
            @unlink($target);
            throw $error;
        }
        return [
            'url' => '/plugin-media/' . rawurlencode($pluginId) . '/' . $filename,
            'filename' => $filename,
            'kind' => $kind,
            'mime' => $mime,
            'size' => (int)filesize($target),
        ];
    }

    public function find(string $pluginId, string $filename): ?array
    {
        if (!$this->validPluginId($pluginId) || !preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp|mp4|webm|mov)$/D', $filename)) return null;
        $path = $this->directory($pluginId) . '/' . $filename;
        if (!is_file($path)) return null;
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        foreach (self::TYPES as $mime => [$allowedExtension]) {
            if ($extension === $allowedExtension) return ['path' => $path, 'mime' => $mime, 'size' => (int)filesize($path)];
        }
        return null;
    }

    public function remove(string $pluginId, string $filename): bool
    {
        $media = $this->find($pluginId, $filename);
        return $media !== null && unlink($media['path']);
    }

    private function stripMetadata(string $path): void
    {
        if (!is_executable('/usr/bin/exiftool')) throw new \RuntimeException('Media metadata sanitizer is unavailable.');
        $process = proc_open(
            ['/usr/bin/exiftool', '-all=', '-overwrite_original', $path],
            [
                ['file', '/dev/null', 'r'],
                ['file', '/dev/null', 'a'],
                ['file', '/dev/null', 'a'],
            ],
            $pipes,
        );
        if (!is_resource($process) || proc_close($process) !== 0) {
            throw new \RuntimeException('Media metadata could not be removed.');
        }
    }

    private function directory(string $pluginId): string
    {
        return rtrim($this->dataDir, '/') . '/plugin-media/' . $pluginId;
    }

    private function assertPluginId(string $pluginId): void
    {
        if (!$this->validPluginId($pluginId)) throw new \InvalidArgumentException('Plugin ID is invalid.');
    }

    private function validPluginId(string $pluginId): bool
    {
        return (bool)preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $pluginId);
    }
}
