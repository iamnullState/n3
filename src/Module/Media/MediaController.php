<?php

declare(strict_types=1);

namespace N3\Module\Media;

use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Security\CurrentPrincipalProvider;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;
use Throwable;

final readonly class MediaController
{
    public function __construct(
        private View $view,
        private CurrentPrincipalProvider $principals,
        private MediaService $media,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
        private FileLogger $logger,
    ) {
    }

    public function index(Request $request): Response
    {
        $authorization = $this->authorize();
        if ($authorization !== null) {
            return $authorization;
        }

        try {
            return $this->renderLibrary([], ['label' => ''], 200, $this->flash->pull());
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'media_library_failed');
        }
    }

    public function upload(Request $request): Response
    {
        $authorization = $this->authorize();
        if ($authorization !== null) {
            return $authorization;
        }
        $label = $request->input('label');
        $label = is_string($label) ? $label : '';
        if (!$this->csrf->verify('media_upload', $request->input('_csrf'))) {
            return $this->renderLibrary(
                ['form' => 'Your upload form expired. Refresh and try again.'],
                ['label' => $label],
                419,
            );
        }

        try {
            $outcome = $this->media->upload($label, $request->uploadedFile('image'), $request->clientIp());
            if ($outcome->rateLimited) {
                return $this->renderLibrary(
                    ['form' => 'Too many upload attempts. Wait before trying again.'],
                    ['label' => $label],
                    429,
                )->withHeader('Retry-After', '3600');
            }
            if (!$outcome->succeeded()) {
                return $this->renderLibrary($outcome->errors, ['label' => $label], 422);
            }
            $this->flash->set('success', 'Image sanitized and added to the private library.');

            return $this->private(Response::redirect('/admin/media'));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'media_upload_failed');
        }
    }

    public function preview(Request $request): Response
    {
        $authorization = $this->authorize();
        if ($authorization !== null) {
            return $authorization;
        }
        $publicId = $request->routeParameter('id', '');
        if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return $this->notFound();
        }

        try {
            $preview = $this->media->preview($publicId);
            if ($preview === null) {
                return $this->notFound();
            }

            return (new Response($preview->contents, 200, [
                'Content-Type' => 'image/webp',
                'Content-Length' => (string) strlen($preview->contents),
                'Content-Disposition' => 'inline',
                'Cache-Control' => 'private, max-age=300',
                'ETag' => '"' . $preview->etag . '"',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'media_preview_failed');
        }
    }

    public function regenerate(Request $request): Response
    {
        return $this->lifecycle($request, 'regenerate');
    }

    public function delete(Request $request): Response
    {
        return $this->lifecycle($request, 'delete');
    }

    private function lifecycle(Request $request, string $operation): Response
    {
        $authorization = $this->authorize();
        if ($authorization !== null) {
            return $authorization;
        }
        $publicId = $request->routeParameter('id', '');
        if (!is_string($publicId) || !preg_match('/^[a-f0-9]{32}$/D', $publicId)) {
            return $this->notFound();
        }
        if (!$this->csrf->verify('media_' . $operation . ':' . $publicId, $request->input('_csrf'))) {
            return $this->renderLibrary(['form' => 'Your Media action expired. Refresh and try again.'], ['label' => ''], 419);
        }

        try {
            $outcome = $operation === 'delete' ? $this->media->delete($publicId) : $this->media->regenerate($publicId);
            if ($outcome->status === 'missing') {
                return $this->notFound();
            }
            if ($outcome->status === 'in_use') {
                return $this->renderLibrary(
                    ['form' => 'This image is attached to content. Detach it from every Page before deletion.'],
                    ['label' => ''],
                    409,
                );
            }
            $this->flash->set('success', $operation === 'delete'
                ? 'Unused image deleted from the catalog and private storage.'
                : 'Preview regenerated from the verified private master.');

            return $this->private(Response::redirect('/admin/media'));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'media_' . $operation . '_failed');
        }
    }

    /** @param array<string, string> $errors @param array{label: string} $values @param array{type: string, message: string}|null $flash */
    private function renderLibrary(array $errors, array $values, int $status, ?array $flash = null): Response
    {
        return $this->private(Response::html($this->view->render('media/library', [
            'pageTitle' => 'Media — N3',
            'metaDescription' => 'Private N3 image library.',
            'robots' => 'noindex,nofollow',
            'items' => $items = $this->media->library(),
            'assets' => array_map(static fn (MediaLibraryItem $item): MediaAsset => $item->asset, $items),
            'errors' => $errors,
            'values' => $values,
            'csrf' => $this->csrf->token('media_upload'),
            'lifecycleCsrf' => array_reduce($items, function (array $tokens, MediaLibraryItem $item): array {
                $id = $item->asset->publicId;
                $tokens[$id] = [
                    'regenerate' => $this->csrf->token('media_regenerate:' . $id),
                    'delete' => $this->csrf->token('media_delete:' . $id),
                ];
                return $tokens;
            }, []),
            'flash' => $flash,
        ]), $status));
    }

    private function authorize(): ?Response
    {
        $principal = $this->principals->current();
        if ($principal === null) {
            return $this->private(Response::redirect('/login'));
        }
        if ($principal->authority !== 'admin') {
            return $this->private(Response::html($this->view->render('errors/403', [
                'pageTitle' => 'Access denied',
                'metaDescription' => 'Access denied — N3',
            ]), 403));
        }

        return null;
    }

    private function notFound(): Response
    {
        return $this->private(Response::html($this->view->render('errors/404', [
            'pageTitle' => 'Image not found',
            'metaDescription' => 'Image not found — N3',
        ]), 404));
    }

    private function unavailable(Throwable $exception, string $event): Response
    {
        $this->logger->error($event, ['exception' => $exception::class]);

        return $this->private(Response::html($this->view->render('media/error', [
            'pageTitle' => 'Media unavailable — N3',
            'metaDescription' => 'The private Media library is temporarily unavailable.',
            'message' => 'The Media library is temporarily unavailable. Check its migration, storage permissions, and database access.',
            'robots' => 'noindex,nofollow',
        ]), 503));
    }

    private function private(Response $response): Response
    {
        return $response
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
