<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Identity\AuthenticationService;
use N3\App\Identity\AuthSessionManager;
use N3\App\Identity\RecoveryService;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\SessionStore;
use N3\Core\View\View;

final readonly class AccessController
{
    public function __construct(
        private View $view,
        private AuthenticationService $authentication,
        private RecoveryService $recovery,
        private AuthSessionManager $authSession,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
        private SessionStore $session,
    ) {
    }

    public function showLogin(Request $request): Response
    {
        if ($this->authSession->current() !== null) {
            return Response::redirect('/account');
        }

        return $this->renderLogin();
    }

    public function login(Request $request): Response
    {
        if (!$this->csrf->verify('login', $request->input('_csrf'))) {
            return $this->renderLogin('Your form expired. Refresh the page and try again.', 419);
        }
        $outcome = $this->authentication->authenticate(
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            $request->clientIp(),
            (string) $request->attribute('request_id', ''),
        );
        if (!$outcome->authenticated()) {
            $message = $outcome->verificationRequired
                ? 'Verify your email before signing in.'
                : 'The email address or password is incorrect.';
            return $this->renderLogin($message, 422, (string) $request->input('email', ''));
        }
        $this->authSession->login($outcome->user);

        return Response::redirect('/account');
    }

    public function logout(Request $request): Response
    {
        if (!$this->csrf->verify('logout', $request->input('_csrf'))) {
            return Response::html('The logout form expired.', 419);
        }
        $this->authSession->logout();

        return Response::redirect('/login');
    }

    public function account(Request $request): Response
    {
        $user = $this->authSession->current();
        if ($user === null) {
            return Response::redirect('/login');
        }

        return Response::html($this->view->render('identity/account', [
            'pageTitle' => 'Your account — N3',
            'metaDescription' => 'Your N3 account.',
            'user' => $user,
            'logoutCsrf' => $this->csrf->token('logout'),
        ]));
    }

    public function showForgot(Request $request): Response
    {
        return Response::html($this->view->render('identity/forgot', [
            'pageTitle' => 'Forgot password — N3',
            'metaDescription' => 'Request a password reset.',
            'csrf' => $this->csrf->token('forgot_password'),
            'flash' => $this->flash->pull(),
        ]));
    }

    public function requestReset(Request $request): Response
    {
        if (!$this->csrf->verify('forgot_password', $request->input('_csrf'))) {
            return Response::html('The password reset request expired.', 419);
        }
        $this->recovery->request(
            (string) $request->input('email', ''),
            $request->clientIp(),
            (string) $request->attribute('request_id', ''),
        );
        $this->flash->set('success', 'If the account can be recovered, a reset message is ready.');

        return Response::redirect('/forgot-password');
    }

    public function showReset(Request $request): Response
    {
        $token = $request->query('token');
        if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            $this->session->put('_reset_token_hash', hash('sha256', $token));
            return Response::redirect('/reset-password');
        }

        return $this->renderReset();
    }

    public function reset(Request $request): Response
    {
        if (!$this->csrf->verify('reset_password', $request->input('_csrf'))) {
            return $this->renderReset(['form' => 'The password reset form expired.'], 419);
        }
        $hash = $this->session->get('_reset_token_hash');
        if (!is_string($hash)) {
            return $this->renderReset(['form' => 'That password reset link is invalid or expired.'], 422);
        }
        $errors = $this->recovery->reset(
            $hash,
            (string) $request->input('password', ''),
            (string) $request->input('password_confirmation', ''),
            $request->clientIp(),
            (string) $request->attribute('request_id', ''),
        );
        if ($errors !== []) {
            if (isset($errors['form'])) {
                $this->session->remove('_reset_token_hash');
            }
            return $this->renderReset($errors, 422);
        }
        $this->session->remove('_reset_token_hash');
        $this->authSession->logout();
        $this->flash->set('success', 'Your password was changed. Sign in with the new password.');

        return Response::redirect('/login');
    }

    private function renderLogin(?string $error = null, int $status = 200, string $email = ''): Response
    {
        return Response::html($this->view->render('identity/login', [
            'pageTitle' => 'Sign in — N3',
            'metaDescription' => 'Sign in to N3.',
            'csrf' => $this->csrf->token('login'),
            'error' => $error,
            'email' => $email,
            'flash' => $this->flash->pull(),
        ]), $status);
    }

    /** @param array<string, string> $errors */
    private function renderReset(array $errors = [], int $status = 200): Response
    {
        return Response::html($this->view->render('identity/reset', [
            'pageTitle' => 'Reset password — N3',
            'metaDescription' => 'Choose a new password.',
            'csrf' => $this->csrf->token('reset_password'),
            'hasToken' => is_string($this->session->get('_reset_token_hash')),
            'errors' => $errors,
        ]), $status);
    }
}
