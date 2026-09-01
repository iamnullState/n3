<?php

declare(strict_types=1);

namespace N3\Module\Media;

final readonly class MediaPreview
{
    public function __construct(public string $contents, public string $etag)
    {
        if ($contents === '' || !preg_match('/^[a-f0-9]{64}$/D', $etag)) {
            throw new \InvalidArgumentException('Media preview data is invalid.');
        }
    }
}
