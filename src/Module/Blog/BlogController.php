<?php

declare(strict_types=1);

namespace N3\Module\Blog;

use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Security\CurrentActor;
use N3\Core\Security\CurrentActorProvider;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;
use Throwable;

final readonly class BlogController
{
    public function __construct(
        private View $view,
        private CurrentActorProvider $actors,
        private BlogService $blog,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
        private FileLogger $logger,
    ) {
    }

    public function adminIndex(Request $request): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }

        try {
            return $this->private(Response::html($this->view->render('blog/admin/index', [
                'pageTitle' => 'Blog — N3',
                'metaDescription' => 'Manage Blog posts.',
                'robots' => 'noindex,nofollow',
                'posts' => $this->blog->listForAdministration(),
                'flash' => $this->flash->pull(),
            ])));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_admin_list_failed', true);
        }
    }

    public function create(Request $request): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }

        return $this->renderEditor(null, $this->emptyValues(), [], 200, 'create');
    }

    public function store(Request $request): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }
        $values = $this->values($request);
        if (!$this->csrf->verify('blog_create', $request->input('_csrf'))) {
            return $this->renderEditor(null, $values, ['form' => 'Your Blog form expired. Refresh and try again.'], 419, 'create');
        }
        try {
            $outcome = $this->blog->createDraft(
                $values['title'], $values['slug'], $values['excerpt'], $values['body'],
                $admin->id, (string) $request->attribute('request_id', ''),
            );
            if (!$outcome->succeeded()) {
                return $this->renderEditor(null, $values, $outcome->errors, 422, 'create');
            }
            $this->flash->set('success', 'Draft Blog post created.');

            return $this->private(Response::redirect('/admin/blog/' . $outcome->postId . '/edit'));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_create_failed', true);
        }
    }

    public function edit(Request $request): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }
        try {
            $post = $this->routePost($request);
            if ($post === null) { return $this->notFound(true); }

            return $this->renderEditor($post, $this->postValues($post), [], 200, 'edit');
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_edit_failed', true);
        }
    }

    public function update(Request $request): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }
        try {
            $post = $this->routePost($request);
            if ($post === null) { return $this->notFound(true); }
            $values = $this->values($request);
            if (!$this->csrf->verify('blog_update_' . $post->id, $request->input('_csrf'))) {
                return $this->renderEditor($post, $values, ['form' => 'Your Blog form expired. Refresh and try again.'], 419, 'edit');
            }
            $version = $this->version($request);
            if ($version === null) {
                return $this->renderEditor($post, $values, ['form' => 'The Blog post version is invalid. Reload and try again.'], 422, 'edit');
            }
            $outcome = $this->blog->updateDraft(
                $post->id, $values['title'], $values['slug'], $values['excerpt'], $values['body'],
                $admin->id, $version, (string) $request->attribute('request_id', ''),
            );
            if ($outcome->conflict) {
                return $this->renderEditor($post, $values, ['form' => 'This draft changed or was published. Reload before editing again.'], 409, 'edit');
            }
            if ($outcome->errors !== []) {
                return $this->renderEditor($post, $values, $outcome->errors, 422, 'edit');
            }
            $this->flash->set('success', 'Draft Blog post saved.');

            return $this->private(Response::redirect('/admin/blog/' . $post->id . '/edit'));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_update_failed', true);
        }
    }

    public function preview(Request $request): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }
        try {
            $post = $this->routePost($request);
            if ($post === null) { return $this->notFound(true); }

            return $this->private(Response::html($this->view->render('blog/post', [
                'pageTitle' => $post->title . ' — Blog preview — N3',
                'metaDescription' => $post->excerpt,
                'robots' => 'noindex,nofollow',
                'post' => $post,
                'preview' => true,
            ])));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_preview_failed', true);
        }
    }

    public function publish(Request $request): Response
    {
        return $this->changeStatus($request, 'publish');
    }

    public function unpublish(Request $request): Response
    {
        return $this->changeStatus($request, 'unpublish');
    }

    public function publicIndex(Request $request): Response
    {
        $page = $this->pageNumber($request);
        if ($page === null) {
            return Response::html('The Blog page number is invalid.', 400);
        }
        try {
            $listing = $this->blog->listing($page);
            if ($listing === null) { return $this->notFound(false); }

            return Response::html($this->view->render('blog/index', [
                'pageTitle' => ($page === 1 ? 'Blog' : 'Blog — Page ' . $page) . ' — N3',
                'metaDescription' => 'Published Blog posts.',
                'listing' => $listing,
            ]));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_public_list_failed', false);
        }
    }

    public function publicPost(Request $request): Response
    {
        $slug = $request->routeParameter('slug', '');
        if (!is_string($slug)) { return $this->notFound(false); }
        try {
            $post = $this->blog->findPublished($slug);
            if ($post === null) { return $this->notFound(false); }

            return Response::html($this->view->render('blog/post', [
                'pageTitle' => $post->title . ' — Blog — N3',
                'metaDescription' => $post->excerpt,
                'post' => $post,
                'preview' => false,
            ]));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_public_post_failed', false);
        }
    }

    private function changeStatus(Request $request, string $action): Response
    {
        $admin = $this->administrator();
        if ($admin instanceof Response) { return $admin; }
        try {
            $post = $this->routePost($request);
            if ($post === null) { return $this->notFound(true); }
            if (!$this->csrf->verify('blog_' . $action . '_' . $post->id, $request->input('_csrf'))) {
                return $this->private(Response::html('The Blog status form expired.', 419));
            }
            $version = $this->version($request);
            if ($version === null) {
                $this->flash->set('warning', 'The Blog post version is invalid. Reload and try again.');
                return $this->private(Response::redirect('/admin/blog/' . $post->id . '/edit'));
            }
            $outcome = $action === 'publish'
                ? $this->blog->publish($post->id, $admin->id, $version, (string) $request->attribute('request_id', ''))
                : $this->blog->unpublish($post->id, $admin->id, $version, (string) $request->attribute('request_id', ''));
            if ($outcome->errors !== []) {
                return $this->renderEditor($post, $this->postValues($post), $outcome->errors, 422, 'edit');
            }
            $this->flash->set(
                $outcome->conflict ? 'warning' : 'success',
                $outcome->conflict ? 'The Blog post changed. Reload before changing its status.'
                    : ($action === 'publish' ? 'Blog post published.' : 'Blog post unpublished.'),
            );

            return $this->private(Response::redirect('/admin/blog/' . $post->id . '/edit'));
        } catch (Throwable $exception) {
            return $this->unavailable($exception, 'blog_status_failed', true);
        }
    }

    private function administrator(): CurrentActor|Response
    {
        $actor = $this->actors->current();
        if ($actor === null) { return $this->private(Response::redirect('/login')); }
        if ($actor->authority !== 'admin') {
            return $this->private(Response::html($this->view->render('errors/403', [
                'pageTitle' => 'Access denied', 'metaDescription' => 'Access denied — N3',
            ]), 403));
        }

        return $actor;
    }

    private function routePost(Request $request): ?BlogPost
    {
        $id = $request->routeParameter('id', '');

        return is_string($id) && ctype_digit($id) && (int) $id > 0 ? $this->blog->find((int) $id) : null;
    }

    private function version(Request $request): ?int
    {
        $version = $request->input('lock_version');

        return is_string($version) && ctype_digit($version) && (int) $version > 0 ? (int) $version : null;
    }

    private function pageNumber(Request $request): ?int
    {
        $page = $request->query('page', '1');

        return is_string($page) && ctype_digit($page) && (int) $page >= 1 && (int) $page <= BlogService::MAXIMUM_PAGE
            ? (int) $page
            : null;
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string} */
    private function values(Request $request): array
    {
        return [
            'title' => is_string($request->input('title')) ? (string) $request->input('title') : '',
            'slug' => is_string($request->input('slug')) ? (string) $request->input('slug') : '',
            'excerpt' => is_string($request->input('excerpt')) ? (string) $request->input('excerpt') : '',
            'body' => is_string($request->input('body')) ? (string) $request->input('body') : '',
        ];
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string} */
    private function postValues(BlogPost $post): array
    {
        return ['title' => $post->title, 'slug' => $post->slug, 'excerpt' => $post->excerpt, 'body' => $post->body];
    }

    /** @return array{title: string, slug: string, excerpt: string, body: string} */
    private function emptyValues(): array
    {
        return ['title' => '', 'slug' => '', 'excerpt' => '', 'body' => ''];
    }

    /** @param array{title: string, slug: string, excerpt: string, body: string} $values @param array<string, string> $errors */
    private function renderEditor(?BlogPost $post, array $values, array $errors, int $status, string $mode): Response
    {
        return $this->private(Response::html($this->view->render('blog/admin/editor', [
            'pageTitle' => ($post === null ? 'Create Blog post' : 'Edit ' . $post->title) . ' — N3',
            'metaDescription' => 'Edit an N3 Blog post.',
            'robots' => 'noindex,nofollow',
            'post' => $post,
            'values' => $values,
            'errors' => $errors,
            'mode' => $mode,
            'csrf' => $this->csrf->token($post === null ? 'blog_create' : 'blog_update_' . $post->id),
            'publishCsrf' => $post === null ? '' : $this->csrf->token('blog_publish_' . $post->id),
            'unpublishCsrf' => $post === null ? '' : $this->csrf->token('blog_unpublish_' . $post->id),
            'flash' => $this->flash->pull(),
        ]), $status));
    }

    private function notFound(bool $private): Response
    {
        $response = Response::html($this->view->render('errors/404', [
            'pageTitle' => 'Blog post not found', 'metaDescription' => 'Blog post not found — N3',
        ]), 404);

        return $private ? $this->private($response) : $response;
    }

    private function unavailable(Throwable $exception, string $event, bool $private): Response
    {
        $this->logger->error($event, ['exception' => $exception::class]);
        $response = Response::html($this->view->render('blog/error', [
            'pageTitle' => 'Blog unavailable — N3',
            'metaDescription' => 'The Blog is temporarily unavailable.',
            'robots' => $private ? 'noindex,nofollow' : null,
        ]), 503);

        return $private ? $this->private($response) : $response;
    }

    private function private(Response $response): Response
    {
        return $response->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
