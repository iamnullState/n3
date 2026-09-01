<?php

declare(strict_types=1);

namespace N3\Core\Http;

final readonly class UploadedFile
{
    public function __construct(
        public string $temporaryPath,
        public int $error,
        public int $reportedSize,
        private bool $requireHttpUpload = false,
    ) {
    }

    /** @param array<string, mixed> $file */
    public static function fromGlobal(array $file): ?self
    {
        $path = $file['tmp_name'] ?? null;
        $error = $file['error'] ?? null;
        $size = $file['size'] ?? null;

        if (!is_string($path) || !is_int($error) || !is_int($size)) {
            return null;
        }

        return new self($path, $error, $size, true);
    }

    public function isReadableUpload(): bool
    {
        if ($this->error !== UPLOAD_ERR_OK || $this->temporaryPath === '' || is_link($this->temporaryPath)
            || !is_file($this->temporaryPath) || !is_readable($this->temporaryPath)) {
            return false;
        }

        return !$this->requireHttpUpload || is_uploaded_file($this->temporaryPath);
    }
}
