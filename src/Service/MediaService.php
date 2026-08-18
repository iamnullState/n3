<?php
declare(strict_types=1);

namespace N3\Service;

final class MediaService
{
    public const MAX_BYTES = 52_428_800;

    private const TYPES = [
        'image/jpeg' => ['jpg', 'image'],
        'image/png' => ['png', 'image'],
        'image/gif' => ['gif', 'image'],
        'image/webp' => ['webp', 'image'],
        'image/avif' => ['avif', 'image'],
        'image/bmp' => ['bmp', 'image'],
        'video/mp4' => ['mp4', 'video'],
    ];

    public function __construct(private readonly string $dataDir) {}

    public function store(array $upload): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The media file is larger than the 50 MB limit.'
                : 'The media upload did not complete.';
            throw new DomainException($message, 422);
        }
        $temporary = (string)($upload['tmp_name'] ?? '');
        $size = (int)($upload['size'] ?? 0);
        if ($temporary === '' || !is_uploaded_file($temporary) || $size < 1) throw new DomainException('Choose a non-empty media file.', 422);
        if ($size > self::MAX_BYTES) throw new DomainException('The media file is larger than the 50 MB limit.', 422);

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
        if (!isset(self::TYPES[$mime])) {
            throw new DomainException('Use JPEG, PNG, GIF, WebP, AVIF, BMP, or MP4 media.', 422);
        }
        [$extension, $kind] = self::TYPES[$mime];
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the media directory.');
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $target = $directory . '/' . $filename;
        if (!move_uploaded_file($temporary, $target)) throw new \RuntimeException('Could not store the uploaded media.');
        chmod($target, 0660);
        return ['url' => '/media/' . $filename, 'kind' => $kind, 'mime' => $mime, 'size' => $size];
    }

    public function find(string $filename): ?array
    {
        if (!preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp|mp4)$/D', $filename)) return null;
        $path = $this->directory() . '/' . $filename;
        if (!is_file($path)) return null;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        foreach (self::TYPES as $mime => [$allowedExtension]) {
            if ($extension === $allowedExtension) return ['path' => $path, 'mime' => $mime, 'size' => (int)filesize($path)];
        }
        return null;
    }

    private function directory(): string
    {
        return rtrim($this->dataDir, '/') . '/media';
    }
}
