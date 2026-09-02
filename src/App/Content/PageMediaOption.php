<?php

declare(strict_types=1);

namespace N3\App\Content;

final readonly class PageMediaOption
{
    public function __construct(
        public string $publicId,
        public string $label,
        public int $width,
        public int $height,
    ) {
        if (!preg_match('/^[a-f0-9]{32}$/D', $publicId) || $label === '' || $width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Page Media option data is invalid.');
        }
    }
}
