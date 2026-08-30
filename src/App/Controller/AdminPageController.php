<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Content\Page;
use N3\App\Content\PageService;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\IdentityUser;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;

final readonly class AdminPageController
{
    public function __construct(
        private View $view,
        private PageService $pages,
        private AuthSessionManager $auth,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
    ) {
    }

    public function index(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }

        return Response::html($this->view->render('content/admin/index', [
            'pageTitle' => 'Pages — N3',
            'metaDescription' => 'Manage N3 pages.',
            'pages' => $this->pages->listForAdministration(),
            'flash' => $this->flash->pull(),
        ]));
    }

    public function create(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }

        return $this->renderEditor(null, $this->emptyValues(), [], 200, 'create');
    }

    public function store(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        if (!$this->csrf->verify('page_create', $request->input('_csrf'))) {
            return $this->renderEditor(null, $this->values($request), ['form' => 'Your form expired. Refresh and try again.'], 419, 'create');
        }
        $values = $this->values($request);
        $outcome = $this->pages->createDraft(
            $values['title'], $values['slug'], $values['excerpt'], $values['body'],
            $admin->id, (string) $request->attribute('request_id', ''),
        );
        if (!$outcome->succeeded()) {
            return $this->renderEditor(null, $values, $outcome->errors, 422, 'create');
        }
        $this->flash->set('success', 'Draft page created.');

        return Response::redirect('/admin/pages/' . $outcome->pageId . '/edit');
    }

    public function edit(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        $page = $this->routePage($request);
        if ($page === null) { return $this->notFound(); }

        return $this->renderEditor($page, $this->pageValues($page), [], 200, 'edit');
    }

    public function update(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        $page = $this->routePage($request);
        if ($page === null) { return $this->notFound(); }
        $values = $this->values($request);
        if (!$this->csrf->verify('page_update_' . $page->id, $request->input('_csrf'))) {
            return $this->renderEditor($page, $values, ['form' => 'Your form expired. Refresh and try again.'], 419, 'edit');
        }
        $version = $this->version($request);
        if ($version === null) {
            return $this->renderEditor($page, $values, ['form' => 'The page version is invalid. Reload and try again.'], 422, 'edit');
        }
        $outcome = $this->pages->updateDraft(
            $page->id, $values['title'], $values['slug'], $values['excerpt'], $values['body'],
            $admin->id, $version, (string) $request->attribute('request_id', ''),
        );
        if ($outcome->conflict) {
            return $this->renderEditor($page, $values, ['form' => 'This draft changed or was published. Reload before editing again.'], 409, 'edit');
        }
        if ($outcome->errors !== []) {
            return $this->renderEditor($page, $values, $outcome->errors, 422, 'edit');
        }
        $this->flash->set('success', 'Draft saved.');

        return Response::redirect('/admin/pages/' . $page->id . '/edit');
    }

    public function preview(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        $page = $this->routePage($request);
        if ($page === null) { return $this->notFound(); }

        return Response::html($this->view->render('content/page', [
            'pageTitle' => $page->title . ' — Preview — N3',
            'metaDescription' => $page->excerpt,
            'page' => $page,
            'preview' => true,
            'robots' => 'noindex,nofollow',
        ]));
    }

    public function publish(Request $request): Response
    {
        return $this->changeStatus($request, 'publish');
    }

    public function unpublish(Request $request): Response
    {
        return $this->changeStatus($request, 'unpublish');
    }

    private function changeStatus(Request $request, string $action): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        $page = $this->routePage($request);
        if ($page === null) { return $this->notFound(); }
        if (!$this->csrf->verify('page_' . $action . '_' . $page->id, $request->input('_csrf'))) {
            return Response::html('The page status form expired.', 419);
        }
        $version = $this->version($request);
        if ($version === null) {
            $this->flash->set('warning', 'The page version is invalid. Reload and try again.');
            return Response::redirect('/admin/pages/' . $page->id . '/edit');
        }
        $outcome = $action === 'publish'
            ? $this->pages->publish($page->id, $admin->id, $version, (string) $request->attribute('request_id', ''))
            : $this->pages->unpublish($page->id, $admin->id, $version, (string) $request->attribute('request_id', ''));
        if ($outcome->errors !== []) {
            return $this->renderEditor($page, $this->pageValues($page), $outcome->errors, 422, 'edit');
        }
        $this->flash->set(
            $outcome->conflict ? 'warning' : 'success',
            $outcome->conflict ? 'The page changed. Reload before changing its status.' : ($action === 'publish' ? 'Page published.' : 'Page unpublished.'),
        );

        return Response::redirect('/admin/pages/' . $page->id . '/edit');
    }

    private function adminOrResponse(): IdentityUser|Response
    {
        $user = $this->auth->current();
        if ($user === null) { return Response::redirect('/login'); }
        if ($user->role !== 'admin') {
            return Response::html($this->view->render('errors/403', [
                'pageTitle' => 'Access denied',
                'metaDescription' => 'Access denied — N3',
            ]), 403);
        }

        return $user;
    }

    private function routePage(Request $request): ?Page
    {
        $id = $request->routeParameter('id', '');

        return is_string($id) && ctype_digit($id) && (int) $id > 0 ? $this->pages->find((int) $id) : null;
    }

    private function version(Request $request): ?int
    {
        $version = $request->input('lock_version');

        return is_string($version) && ctype_digit($version) && (int) $version > 0 ? (int) $version : null;
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string} */
    private function values(Request $request): array
    {
        return [
            'title' => (string) $request->input('title', ''),
            'slug' => (string) $request->input('slug', ''),
            'excerpt' => (string) $request->input('excerpt', ''),
            'body' => (string) $request->input('body', ''),
        ];
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string} */
    private function pageValues(Page $page): array
    {
        return ['title' => $page->title, 'slug' => $page->slug, 'excerpt' => $page->excerpt, 'body' => $page->body];
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string} */
    private function emptyValues(): array
    {
        return ['title' => '', 'slug' => '', 'excerpt' => '', 'body' => ''];
    }

    /** @param array{title: string, slug: string, excerpt: string, body: string} $values @param array<string, string> $errors */
    private function renderEditor(?Page $page, array $values, array $errors, int $status, string $mode): Response
    {
        return Response::html($this->view->render('content/admin/editor', [
            'pageTitle' => ($page === null ? 'Create page' : 'Edit ' . $page->title) . ' — N3',
            'metaDescription' => 'Edit an N3 page.',
            'page' => $page,
            'values' => $values,
            'errors' => $errors,
            'mode' => $mode,
            'csrf' => $this->csrf->token($page === null ? 'page_create' : 'page_update_' . $page->id),
            'publishCsrf' => $page === null ? '' : $this->csrf->token('page_publish_' . $page->id),
            'unpublishCsrf' => $page === null ? '' : $this->csrf->token('page_unpublish_' . $page->id),
            'flash' => $this->flash->pull(),
        ]), $status);
    }

    private function notFound(): Response
    {
        return Response::html($this->view->render('errors/404', [
            'pageTitle' => 'Page not found',
            'metaDescription' => 'Page not found — N3',
        ]), 404);
    }
}
