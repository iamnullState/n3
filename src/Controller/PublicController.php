<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Config;
use N3\Http\Response;
use N3\Repository\PublicPageRepository;
use N3\Service\PublishingService;
use N3\Service\PageProjectionService;
use N3\View\ViewRenderer;

final class PublicController
{
    public function __construct(
        private readonly PublicPageRepository $pages,
        private readonly ViewRenderer $views,
        private readonly PublishingService $publishing,
        private readonly PageProjectionService $pageProjection,
    ) {}

    public function home(string $query, string $tag): never
    {
        $body = $this->views->render('public/home', [
            'appName' => Config::appName(),
            'directory' => $this->directory(),
            'pages' => $this->pages->search($query, $tag),
            'query' => $query,
            'tag' => $tag,
        ]);
        (new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']))->send();
    }

    public function tags(): never
    {
        $body = $this->views->render('public/tags', [
            'appName' => Config::appName(),
            'directory' => $this->directory(),
            'tags' => $this->pages->tags(),
        ]);
        (new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']))->send();
    }

    public function page(string $slug): never
    {
        $page = $this->pages->findBySlug($slug);
        if ($page === null) {
            (new Response('Public page not found.', 404, ['Content-Type' => 'text/html; charset=utf-8']))->send();
        }
        $projection = $this->pageProjection->publicDetail($page);
        $body = $this->views->render('public/page', [
            'appName' => Config::appName(),
            'canonical' => Config::appUrl() . '/p/' . rawurlencode($page['slug']),
            'content' => $this->publishing->content($page['content']),
            'description' => $this->description($page['content']),
            'directory' => $this->directory($page['slug']),
            'featureImage' => $projection['feature_image'],
            'page' => $projection['page'],
            'pageInformation' => $projection['page_information'],
            'references' => $this->pages->referencesForPage((int)$page['id']),
            'related' => $this->pages->relatedForPage((int)$page['id']),
            'tags' => $this->pages->tagsForPage((int)$page['id']),
        ]);
        (new Response($body, 200, ['Content-Type' => 'text/html; charset=utf-8']))->send();
    }

    public function legacyRedirect(int $id): never
    {
        $slug = $this->pages->slugForId($id);
        if ($slug === null) {
            (new Response('Public page not found.', 404, ['Content-Type' => 'text/html; charset=utf-8']))->send();
        }
        Response::redirect('/p/' . rawurlencode($slug), 301)->send();
    }

    private function directory(?string $currentSlug = null): string
    {
        $data = $this->pages->directory();
        return $this->views->render('public/directory', $data + ['currentSlug' => $currentSlug]);
    }

    private function description(string $html): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''), 0, 180);
    }

}
