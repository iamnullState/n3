<?php

declare(strict_types=1);

namespace N3\App\Site;

final readonly class SiteIdentity
{
    public function __construct(
        public string $name,
        public string $tagline,
        public string $contactEmail,
        public string $primaryColor,
        public string $logoPath,
        public int $lockVersion,
    ) {
    }
}
