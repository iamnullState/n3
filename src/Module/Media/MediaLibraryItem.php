<?php

declare(strict_types=1);

namespace N3\Module\Media;

final readonly class MediaLibraryItem
{
    public function __construct(public MediaAsset $asset, public MediaUsage $usage)
    {
    }
}
