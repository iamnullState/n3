<?php
declare(strict_types=1);

namespace N3\Service;

use Closure;
use N3\Repository\ProfileRepository;

final class ProfileAvatarService
{
    public const MAX_BYTES = 5_242_880;
    public const MAX_DIMENSION = 4096;
    public const MAX_PIXELS = 16_777_216;

    private const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    private readonly Closure $isUploadedFile;
    private readonly Closure $moveUploadedFile;

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly string $dataDir,
        ?callable $isUploadedFile = null,
        ?callable $moveUploadedFile = null,
    ) {
        $this->isUploadedFile = Closure::fromCallable($isUploadedFile ?? 'is_uploaded_file');
        $this->moveUploadedFile = Closure::fromCallable($moveUploadedFile ?? 'move_uploaded_file');
    }

    public function storeForUser(int $userId, array $upload): array
    {
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The avatar is larger than the 5 MB limit.'
                : 'The avatar upload did not complete.';
            throw new DomainException($message, 422);
        }

        $temporary = (string)($upload['tmp_name'] ?? '');
        $reportedSize = (int)($upload['size'] ?? 0);
        if ($temporary === '' || !($this->isUploadedFile)($temporary) || $reportedSize < 1 || !is_file($temporary)) {
            throw new DomainException('Choose a non-empty avatar image.', 422);
        }
        $actualSize = filesize($temporary);
        if ($actualSize === false || $actualSize < 1) throw new DomainException('Choose a non-empty avatar image.', 422);
        if ($actualSize > self::MAX_BYTES || $reportedSize > self::MAX_BYTES) {
            throw new DomainException('The avatar is larger than the 5 MB limit.', 422);
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($temporary) ?: '';
        $extension = self::TYPES[$mime] ?? null;
        if ($extension === null) throw new DomainException('Use a JPEG, PNG, GIF, or WebP avatar image.', 422);

        $dimensions = @getimagesize($temporary);
        if (!is_array($dimensions) || (int)$dimensions[0] < 1 || (int)$dimensions[1] < 1) {
            throw new DomainException('The avatar image could not be read.', 422);
        }
        $detectedMime = (string)($dimensions['mime'] ?? '');
        if ($detectedMime !== $mime) throw new DomainException('The avatar image type is invalid.', 422);
        if (!$this->validImageEncoding($temporary, $mime)) {
            throw new DomainException('The avatar image could not be read.', 422);
        }
        $width = (int)$dimensions[0];
        $height = (int)$dimensions[1];
        if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
            throw new DomainException('The avatar dimensions exceed the 4096 × 4096 pixel limit.', 422);
        }

        $oldReference = $this->profiles->avatarReferenceForUser($userId);
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Could not create the avatar directory.');
        }
        do {
            $reference = bin2hex(random_bytes(20)) . '.' . $extension;
            $target = $directory . '/' . $reference;
        } while (is_file($target));
        if (!($this->moveUploadedFile)($temporary, $target)) throw new \RuntimeException('Could not store the uploaded avatar.');
        chmod($target, 0660);

        try {
            if (!$this->profiles->replaceAvatarReference($userId, $oldReference, $reference)) {
                throw new DomainException('The profile changed while the avatar was uploading. Try again.', 409);
            }
        } catch (\Throwable $error) {
            if (is_file($target)) unlink($target);
            throw $error;
        }

        $this->removeReferenceFile($oldReference);
        return ['has_avatar' => true, 'mime' => $mime, 'size' => $actualSize, 'width' => $width, 'height' => $height];
    }

    public function removeForUser(int $userId): bool
    {
        $reference = $this->profiles->avatarReferenceForUser($userId);
        if ($reference === null) return false;
        if (!$this->profiles->replaceAvatarReference($userId, $reference, null)) {
            throw new DomainException('The profile changed while the avatar was being removed. Try again.', 409);
        }
        $this->removeReferenceFile($reference);
        return true;
    }

    public function findForProfile(string $profileSlug, ?int $viewerUserId): ?array
    {
        $profile = $this->profiles->avatarForSlug($profileSlug);
        if ($profile === null) return null;
        $profileUserId = (int)$profile['id'];
        $visibility = (string)$profile['profile_visibility'];
        $allowed = $viewerUserId === $profileUserId
            || ($viewerUserId !== null && $visibility !== 'private')
            || ($viewerUserId === null && $visibility === 'public');
        if (!$allowed) return null;

        $reference = $profile['avatar_reference'];
        if (!is_string($reference) || !$this->validReference($reference)) return null;
        $path = $this->directory() . '/' . $reference;
        if (!is_file($path)) return null;
        $extension = pathinfo($reference, PATHINFO_EXTENSION);
        $mime = array_search($extension, self::TYPES, true);
        if (!is_string($mime) || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== $mime) return null;
        $size = filesize($path);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) return null;
        $dimensions = @getimagesize($path);
        if (!is_array($dimensions) || ($dimensions['mime'] ?? null) !== $mime || !$this->validImageEncoding($path, $mime)) return null;
        $width = (int)$dimensions[0];
        $height = (int)$dimensions[1];
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) return null;
        return ['path' => $path, 'mime' => $mime, 'size' => $size];
    }

    private function removeReferenceFile(?string $reference): void
    {
        if ($reference === null || !$this->validReference($reference)) return;
        $path = $this->directory() . '/' . $reference;
        if (is_file($path)) unlink($path);
    }

    private function validReference(string $reference): bool
    {
        return preg_match('/^[a-f0-9]{40}\.(?:jpg|png|gif|webp)$/D', $reference) === 1;
    }

    private function validImageEncoding(string $path, string $mime): bool
    {
        $data = file_get_contents($path);
        if (!is_string($data)) return false;
        $length = strlen($data);
        if ($mime === 'image/jpeg') return $length >= 4 && str_starts_with($data, "\xff\xd8") && str_ends_with($data, "\xff\xd9");
        if ($mime === 'image/gif') return $length >= 14 && ($data[0] ?? '') === 'G' && str_ends_with($data, ';');
        if ($mime === 'image/webp') {
            if ($length < 20 || substr($data, 0, 4) !== 'RIFF' || substr($data, 8, 4) !== 'WEBP') return false;
            $declared = unpack('Vsize', substr($data, 4, 4));
            return is_array($declared) && (int)$declared['size'] + 8 === $length;
        }
        if ($mime !== 'image/png' || !str_starts_with($data, "\x89PNG\r\n\x1a\n")) return false;

        $offset = 8;
        while ($offset + 12 <= $length) {
            $chunkLength = unpack('Nsize', substr($data, $offset, 4));
            if (!is_array($chunkLength)) return false;
            $size = (int)$chunkLength['size'];
            if ($size < 0 || $offset + 12 + $size > $length) return false;
            $typeAndData = substr($data, $offset + 4, 4 + $size);
            $checksum = substr($data, $offset + 8 + $size, 4);
            if (!hash_equals(hash('crc32b', $typeAndData, true), $checksum)) return false;
            $type = substr($data, $offset + 4, 4);
            $offset += 12 + $size;
            if ($type === 'IEND') return $size === 0 && $offset === $length;
        }
        return false;
    }

    private function directory(): string
    {
        return rtrim($this->dataDir, '/') . '/avatars';
    }
}
