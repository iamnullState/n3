<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\Core\Http\UploadedFile;

interface ImageProcessor
{
    /** @throws MediaUploadRejected */
    public function process(UploadedFile $file): ProcessedImage;
}
