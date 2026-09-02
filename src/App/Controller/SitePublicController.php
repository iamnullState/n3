<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Content\PageMediaProvider;
use N3\App\Content\PageService;
use N3\App\Site\SiteService;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\View\View;

final readonly class SitePublicController
{
    public function __construct(
        private View $view,
        private PageService $pages,
        private SiteService $site,
        private HomeController $fallback,
        private ?PageMediaProvider $media = null,
    ) {
    }

    public function home(Request $request): Response
    {
        $identity = $this->site->identity();
        $page = $this->pages->findPublished('home');
        if ($identity === null || $page === null) {
            return ($this->fallback)($request);
        }

        return Response::html($this->view->render('content/page', [
            'pageTitle' => $identity->name . ' — ' . $identity->tagline,
            'metaDescription' => $page->excerpt !== '' ? $page->excerpt : $identity->tagline,
            'page' => $page,
            'preview' => false,
            'home' => true,
            'media' => $this->media?->attachment($page->id),
            'siteIdentity' => $identity,
            'navigation' => $this->site->publicNavigation(),
        ]));
    }

    public function stylesheet(Request $request): Response
    {
        $identity = $this->site->identity();
        if ($identity === null) {
            return new Response('', 404, ['Content-Type' => 'text/css; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }
        $body = ':root{--brand-primary:' . $identity->primaryColor . '}';
        $etag = hash('sha256', $body);
        if ($request->header('If-None-Match') === '"' . $etag . '"') {
            return new Response('', 304, ['ETag' => '"' . $etag . '"', 'Cache-Control' => 'public, max-age=300']);
        }

        return new Response($body, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'ETag' => '"' . $etag . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
