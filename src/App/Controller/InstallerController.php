<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Install\InstallerConfig;
use N3\App\Install\InstallerAttemptLimiter;
use N3\App\Install\InstallerOperations;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Logging\FileLogger;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\SessionStore;
use N3\Core\View\View;
use Throwable;

final readonly class InstallerController
{
    private const AUTH_KEY = '_installer_authorized';

    public function __construct(
        private View $view,
        private InstallerConfig $config,
        private InstallerOperations $installer,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
        private SessionStore $session,
        private InstallerAttemptLimiter $limiter,
        private FileLogger $logger,
    ) {
    }

    public function show(Request $request): Response
    {
        if (!$this->authorized()) {
            return $this->render('install/authorize', ['error' => null]);
        }

        return $this->renderStatus($request);
    }

    public function authorize(Request $request): Response
    {
        if (!$this->limiter->allows('authorize') || !$this->config->authorizes($request->input('install_token'))) {
            return $this->render('install/authorize', ['error' => 'Installation access was not accepted.'], 403);
        }
        $this->limiter->clear('authorize');
        $this->session->regenerate();
        $this->session->put(self::AUTH_KEY, true);
        $this->csrf->rotate();

        return Response::redirect('/install');
    }

    public function migrate(Request $request): Response
    {
        if (($denied = $this->authorizeMutation($request, 'install_migrate')) !== null) {
            return $denied;
        }
        if ($this->installer->status() === 'complete') {
            return Response::html('', 404);
        }
        try {
            $preflight = $this->installer->preflight($request);
            if (!$preflight->passes()) {
                return $this->renderStatus($request, 'Resolve every failed prerequisite before running migrations.', 422);
            }
            $this->installer->applyMigrations();
            $this->flash->set('success', 'Database preparation completed. Continue with administrator setup.');
            return Response::redirect('/install');
        } catch (Throwable $exception) {
            $this->logFailure('installer_migration_failed', $exception, $request);
            return $this->renderStatus($request, 'Database preparation stopped safely. Correct the hosting configuration, restore if required, and retry.', 503);
        }
    }

    public function createAdmin(Request $request): Response
    {
        if (($denied = $this->authorizeMutation($request, 'install_admin')) !== null) {
            return $denied;
        }
        if (!$this->limiter->allows('admin')) {
            return $this->renderStatus($request, 'Too many setup attempts. Wait before trying again.', 429);
        }
        if ($this->installer->status() !== 'pending_admin' || $this->installer->adminExists()) {
            return $this->renderStatus($request, 'Administrator bootstrap is unavailable in the current installation state.', 409);
        }
        $name = (string) $request->input('display_name', '');
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');
        $confirmation = (string) $request->input('password_confirmation', '');
        $errors = $this->installer->validateAdmin($name, $email, $password, $confirmation);
        if ($errors !== []) {
            return $this->renderStatus($request, null, 422, $errors, ['display_name' => $name, 'email' => $email]);
        }
        try {
            $this->installer->createAdmin($name, $email, $password);
            $this->installer->complete();
        } catch (Throwable $exception) {
            $this->logFailure('installer_admin_failed', $exception, $request);
            return $this->renderStatus($request, 'Administrator setup stopped safely. No password was retained; review the database state and retry or finish setup.', 503);
        }
        $this->limiter->clear('admin');
        $this->session->invalidate();

        return Response::redirect('/login');
    }

    public function complete(Request $request): Response
    {
        if (($denied = $this->authorizeMutation($request, 'install_complete')) !== null) {
            return $denied;
        }
        if ($this->installer->status() !== 'pending_admin' || !$this->installer->adminExists()) {
            return $this->renderStatus($request, 'Installation cannot be finalized in the current state.', 409);
        }
        try {
            $this->installer->complete();
        } catch (Throwable $exception) {
            $this->logFailure('installer_completion_failed', $exception, $request);
            return $this->renderStatus($request, 'Completion could not be recorded. Correct private-storage permissions and retry.', 503);
        }
        $this->session->invalidate();

        return Response::redirect('/login');
    }

    private function renderStatus(
        Request $request,
        ?string $error = null,
        int $statusCode = 200,
        array $errors = [],
        array $old = [],
    ): Response {
        try {
            $preflight = $this->installer->preflight($request);
            $state = $this->installer->status();
            $adminExists = $state !== 'migrations_pending' && $this->installer->adminExists();
        } catch (Throwable) {
            $preflight = null;
            $state = 'migrations_pending';
            $adminExists = false;
            $error ??= 'The hosting environment or database connection is not ready.';
            $statusCode = max($statusCode, 503);
        }

        return $this->render('install/setup', [
            'preflight' => $preflight,
            'state' => $state,
            'adminExists' => $adminExists,
            'reopen' => $this->config->reopen,
            'csrfMigrate' => $this->csrf->token('install_migrate'),
            'csrfAdmin' => $this->csrf->token('install_admin'),
            'csrfComplete' => $this->csrf->token('install_complete'),
            'flash' => $this->flash->pull(),
            'error' => $error,
            'errors' => $errors,
            'old' => $old,
        ], $statusCode);
    }

    private function authorizeMutation(Request $request, string $intent): ?Response
    {
        if (!$this->authorized()) {
            return Response::html('', 404);
        }
        if (!$this->csrf->verify($intent, $request->input('_csrf'))) {
            return Response::html('The setup form expired. Return to setup and try again.', 419);
        }

        return null;
    }

    private function authorized(): bool
    {
        return $this->session->get(self::AUTH_KEY) === true;
    }

    private function render(string $template, array $data, int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data + [
            'pageTitle' => 'Site setup',
            'metaDescription' => 'Private site installation.',
            'robots' => 'noindex, nofollow',
        ], 'layouts/install'), $status)
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function logFailure(string $event, Throwable $exception, Request $request): void
    {
        $this->logger->error($event, [
            'request_id' => (string) $request->attribute('request_id', ''),
            'exception' => $exception::class,
        ]);
    }
}
