<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Logging\FileLogger;
use Throwable;

final readonly class PublicMediaController
{
    public function __construct(private PublicMediaService $media, private FileLogger $logger)
    {
    }

    public function show(Request $request): Response
    {
        $publicId = $request->routeParameter('id', '');
        if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return $this->empty(404);
        }
        try {
            $image = $this->media->image($publicId);
            if ($image === null) {
                return $this->empty(404);
            }
            $etag = '"' . $image->etag . '"';
            $headers = [
                'Content-Type' => 'image/webp',
                'Cache-Control' => 'public, max-age=300',
                'ETag' => $etag,
                'X-Robots-Tag' => 'noindex, nofollow',
            ];
            if ($request->header('If-None-Match') === $etag) {
                return new Response('', 304, $headers);
            }

            return new Response($image->contents, 200, [
                ...$headers,
                'Content-Length' => (string) strlen($image->contents),
                'Content-Disposition' => 'inline',
            ]);
        } catch (Throwable $exception) {
            $this->logger->error('public_media_delivery_failed', ['exception' => $exception::class]);
            return $this->empty(503);
        }
    }

    private function empty(int $status): Response
    {
        return new Response('', $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
