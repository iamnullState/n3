<?php

declare(strict_types=1);

namespace N3\Module\Media;

use DateTimeImmutable;

final readonly class MediaAsset
{
    public function __construct(
        public string $publicId,
        public string $label,
        public int $width,
        public int $height,
        public int $byteSize,
        public string $sha256,
        public DateTimeImmutable $createdAt,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)
            || !mb_check_encoding($label, 'UTF-8') || mb_strlen($label) < 2 || mb_strlen($label) > 120
            || preg_match('/[\x00-\x1F\x7F]/u', $label) === 1
            || $width < 1 || $height < 1 || $byteSize < 1
            || !preg_match('/^[a-f0-9]{64}$/D', $sha256)) {
            throw new \InvalidArgumentException('Media asset data is invalid.');
        }
    }
}
