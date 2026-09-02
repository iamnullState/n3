<?php

declare(strict_types=1);

namespace N3\App\Content;

final readonly class PageMediaAttachment
{
    public function __construct(
        public string $publicId,
        public string $altText,
        public int $width,
        public int $height,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId)
            || !mb_check_encoding($altText, 'UTF-8') || mb_strlen($altText) < 2 || mb_strlen($altText) > 300
            || preg_match('/[\x00-\x1F\x7F]/u', $altText) === 1 || $width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Page Media attachment data is invalid.');
        }
    }
}
