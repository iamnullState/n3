<?php

declare(strict_types=1);

namespace N3\Module\Media;

final readonly class MediaUsage
{
    public function __construct(
        public int $attachments,
        public int $publishedAttachments,
    ) {
        if ($attachments < 0 || $publishedAttachments < 0 || $publishedAttachments > $attachments) {
            throw new \InvalidArgumentException('Media usage counts are invalid.');
        }
    }
}
