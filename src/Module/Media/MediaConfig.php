<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\Core\Config\Environment;
use RuntimeException;

final readonly class MediaConfig
{
    public function __construct(
        public int $maximumUploadBytes,
        public int $maximumPixels,
        public int $maximumDimension,
        public int $maximumProcessedBytes,
        public int $previewMaximumDimension,
        public int $uploadAttemptsPerHour,
        public int $webpQuality,
        public int $previewWebpQuality,
        public string $securityHashKey,
    ) {
        if ($maximumUploadBytes < 1_024 || $maximumUploadBytes > 20_971_520) {
            throw new \InvalidArgumentException('Media upload size must be between 1 KiB and 20 MiB.');
        }
        if ($maximumPixels < 1_000_000 || $maximumPixels > 100_000_000) {
            throw new \InvalidArgumentException('Media pixel limit must be between 1 and 100 megapixels.');
        }
        if ($maximumDimension < 1_000 || $maximumDimension > 30_000) {
            throw new \InvalidArgumentException('Media dimension limit must be between 1,000 and 30,000 pixels.');
        }
        if ($maximumProcessedBytes < 1_024 || $maximumProcessedBytes > 25_165_824) {
            throw new \InvalidArgumentException('Media processed-file limit must be between 1 KiB and 24 MiB.');
        }
        if ($previewMaximumDimension < 64 || $previewMaximumDimension > 2_048) {
            throw new \InvalidArgumentException('Media preview dimension must be between 64 and 2,048 pixels.');
        }
        if ($uploadAttemptsPerHour < 1 || $uploadAttemptsPerHour > 100) {
            throw new \InvalidArgumentException('Media upload attempts must be between 1 and 100 per hour.');
        }
        if ($webpQuality < 60 || $webpQuality > 95 || $previewWebpQuality < 50 || $previewWebpQuality > 90) {
            throw new \InvalidArgumentException('Media WebP quality settings are outside their safe bounds.');
        }
        if (strlen($securityHashKey) < 32) {
            throw new \InvalidArgumentException('Media security hashing requires at least 32 bytes.');
        }
    }

    public static function fromEnvironment(): self
    {
        return new self(
            self::integer('MEDIA_MAX_UPLOAD_BYTES', 10_485_760),
            self::integer('MEDIA_MAX_PIXELS', 25_000_000),
            self::integer('MEDIA_MAX_DIMENSION', 12_000),
            self::integer('MEDIA_MAX_PROCESSED_BYTES', 12_582_912),
            self::integer('MEDIA_PREVIEW_MAX_DIMENSION', 480),
            self::integer('MEDIA_UPLOADS_PER_HOUR', 20),
            self::integer('MEDIA_WEBP_QUALITY', 85),
            self::integer('MEDIA_PREVIEW_WEBP_QUALITY', 78),
            Environment::string('SECURITY_HASH_KEY'),
        );
    }

    private static function integer(string $key, int $default): int
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null || trim((string) $value) === '') {
            return $default;
        }
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException(sprintf('%s must be a whole number.', $key));
        }
        $normalized = trim((string) $value);
        if (!preg_match('/^[0-9]+$/D', $normalized)) {
            throw new RuntimeException(sprintf('%s must be a whole number.', $key));
        }

        return (int) $normalized;
    }
}
