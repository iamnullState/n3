<?php

declare(strict_types=1);

namespace N3\Module\Media;

final readonly class ProcessedImage
{
    public function __construct(
        public string $master,
        public string $preview,
        public int $width,
        public int $height,
    ) {
        if ($width < 1 || $height < 1 || !self::isWebp($master) || !self::isWebp($preview)) {
            throw new \InvalidArgumentException('Processed media must contain valid bounded WebP image data.');
        }
    }

    private static function isWebp(string $data): bool
    {
        return strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP';
    }
}
