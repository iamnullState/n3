<?php

declare(strict_types=1);

namespace N3\Module\Media;

use RuntimeException;

final class MediaUploadRejected extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly string $publicMessage,
    ) {
        parent::__construct($publicMessage);
    }
}
