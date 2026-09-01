<?php

declare(strict_types=1);

namespace N3\Module\Media;

final readonly class MediaUploadOutcome
{
    /** @param array<string, string> $errors */
    public function __construct(
        public ?MediaAsset $asset = null,
        public array $errors = [],
        public bool $rateLimited = false,
    ) {
    }

    public function succeeded(): bool
    {
        return $this->asset !== null && $this->errors === [] && !$this->rateLimited;
    }
}
