<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\IdentityUser;
use N3\App\Site\NavigationItem;
use N3\App\Site\SiteIdentity;
use N3\App\Site\SiteService;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\View\View;

final readonly class SiteAdminController
{
    public function __construct(
        private View $view,
        private SiteService $site,
        private AuthSessionManager $auth,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
    ) {
    }

    public function edit(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        $identity = $this->site->identity();
        if ($identity === null) {
            return $this->unavailable();
        }

        return $this->render($identity, $this->site->administrationNavigation(), [], 200);
    }

    public function update(Request $request): Response
    {
        $admin = $this->adminOrResponse();
        if ($admin instanceof Response) { return $admin; }
        $current = $this->site->identity();
        if ($current === null) { return $this->unavailable(); }
        if (!$this->csrf->verify('site_update', $request->input('_csrf'))) {
            return $this->render($this->identityFromRequest($request, $current), $this->site->administrationNavigation(), ['form' => 'Your site settings form expired. Refresh and try again.'], 419);
        }
        $version = $request->input('lock_version');
        if (!is_string($version) || !ctype_digit($version) || (int) $version < 1) {
            return $this->render($this->identityFromRequest($request, $current), $this->site->administrationNavigation(), ['form' => 'The site settings version is invalid. Reload and try again.'], 422);
        }
        $identity = $this->identityFromRequest($request, $current);
        $outcome = $this->site->update(
            $identity->name, $identity->tagline, $identity->contactEmail, $identity->primaryColor, $identity->logoPath,
            $request->input('navigation'), $admin->id, (int) $version, (string) $request->attribute('request_id', ''),
        );
        if ($outcome->conflict) {
            return $this->render($identity, $this->site->administrationNavigation(), ['form' => 'Site settings changed. Reload before saving again.'], 409);
        }
        if ($outcome->errors !== []) {
            return $this->render($identity, $this->site->administrationNavigation(), $outcome->errors, 422);
        }
        $this->flash->set('success', 'Site identity and navigation saved.');

        return Response::redirect('/admin/site');
    }

    /** @param list<NavigationItem> $navigation @param array<string, string> $errors */
    private function render(SiteIdentity $identity, array $navigation, array $errors, int $status): Response
    {
        return Response::html($this->view->render('site/admin/settings', [
            'pageTitle' => 'Site settings — N3',
            'metaDescription' => 'Manage site identity and navigation.',
            'robots' => 'noindex,nofollow',
            'identity' => $identity,
            'navigation' => $navigation,
            'errors' => $errors,
            'csrf' => $this->csrf->token('site_update'),
            'flash' => $this->flash->pull(),
        ]), $status)->withHeader('Cache-Control', 'no-store')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function identityFromRequest(Request $request, SiteIdentity $fallback): SiteIdentity
    {
        return new SiteIdentity(
            is_string($request->input('site_name')) ? (string) $request->input('site_name') : '',
            is_string($request->input('tagline')) ? (string) $request->input('tagline') : '',
            is_string($request->input('contact_email')) ? (string) $request->input('contact_email') : '',
            is_string($request->input('primary_color')) ? (string) $request->input('primary_color') : '',
            is_string($request->input('logo_path')) ? (string) $request->input('logo_path') : '',
            $fallback->lockVersion,
        );
    }

    private function adminOrResponse(): IdentityUser|Response
    {
        $user = $this->auth->current();
        if ($user === null) { return Response::redirect('/login'); }
        if ($user->role !== 'admin') {
            return Response::html($this->view->render('errors/403', [
                'pageTitle' => 'Access denied', 'metaDescription' => 'Access denied — N3',
            ]), 403)->withHeader('Cache-Control', 'no-store');
        }
        return $user;
    }

    private function unavailable(): Response
    {
        return Response::html($this->view->render('site/admin/unavailable', [
            'pageTitle' => 'Site scaffold unavailable — N3',
            'metaDescription' => 'Site scaffold installation is required.',
            'robots' => 'noindex,nofollow',
        ]), 503)->withHeader('Cache-Control', 'no-store');
    }
}
