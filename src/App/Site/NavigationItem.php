<?php

declare(strict_types=1);

namespace N3\App\Site;

final readonly class NavigationItem
{
    public function __construct(
        public int $pageId,
        public string $label,
        public string $slug,
        public int $position,
        public bool $visible,
        public bool $published,
    ) {
    }
}
