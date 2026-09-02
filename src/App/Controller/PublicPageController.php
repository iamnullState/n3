<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Content\PageService;
use N3\App\Content\PageMediaProvider;
use N3\App\Site\SiteService;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\View\View;

final readonly class PublicPageController
{
    public function __construct(
        private View $view,
        private PageService $pages,
        private ?PageMediaProvider $media = null,
        private ?SiteService $site = null,
    )
    {
    }

    public function show(Request $request): Response
    {
        $page = $this->pages->findPublished((string) $request->routeParameter('slug', ''));
        if ($page === null) {
            return Response::html($this->view->render('errors/404', [
                'pageTitle' => 'Page not found',
                'metaDescription' => 'Page not found — N3',
            ]), 404);
        }

        $identity = $this->site?->identity();

        return Response::html($this->view->render('content/page', [
            'pageTitle' => $page->title . ' — ' . ($identity?->name ?? 'N3'),
            'metaDescription' => $page->excerpt,
            'page' => $page,
            'preview' => false,
            'media' => $this->media?->attachment($page->id),
            'siteIdentity' => $identity,
            'navigation' => $this->site?->publicNavigation() ?? [],
        ]));
    }
}
