<?php

declare(strict_types=1);

namespace N3\App\Controller;

use N3\App\Identity\IdentityConfig;
use N3\App\Identity\RegistrationService;
use N3\Core\Http\Request;
use N3\Core\Http\Response;
use N3\Core\Security\CsrfTokenManager;
use N3\Core\Session\FlashBag;
use N3\Core\Session\SessionStore;
use N3\Core\View\View;

final readonly class IdentityController
{
    public function __construct(
        private View $view,
        private IdentityConfig $config,
        private RegistrationService $registration,
        private CsrfTokenManager $csrf,
        private FlashBag $flash,
        private SessionStore $session,
    ) {
    }

    public function showRegister(Request $request): Response
    {
        return $this->renderRegister();
    }

    public function register(Request $request): Response
    {
        if (!$this->config->registrationEnabled) {
            return Response::html('Registration is disabled.', 503);
        }

        if (!$this->csrf->verify('register', $request->input('_csrf'))) {
            return $this->renderRegister(['form' => 'Your form expired. Refresh the page and try again.'], 419);
        }

        $outcome = $this->registration->register(
            (string) $request->input('display_name', ''),
            (string) $request->input('email', ''),
            (string) $request->input('password', ''),
            (string) $request->input('password_confirmation', ''),
            $request->clientIp(),
            (string) $request->attribute('request_id', ''),
        );

        if ($outcome->errors !== []) {
            return $this->renderRegister(
                $outcome->errors,
                422,
                [
                    'display_name' => (string) $request->input('display_name', ''),
                    'email' => (string) $request->input('email', ''),
                ],
            );
        }

        $message = $outcome->rateLimited
            ? 'Too many attempts. Wait before trying again.'
            : 'If the address can be registered, a verification message is ready.';
        $this->flash->set($outcome->rateLimited ? 'warning' : 'success', $message);

        return Response::redirect('/register');
    }

    public function showVerify(Request $request): Response
    {
        $token = $request->query('token');

        if (is_string($token) && preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
            $this->session->put('_verify_token_hash', hash('sha256', $token));
            return Response::redirect('/verify-email');
        }

        return Response::html($this->view->render('identity/verify', [
            'pageTitle' => 'Verify your email — N3',
            'metaDescription' => 'Confirm your N3 email address.',
            'csrf' => $this->csrf->token('verify_email'),
            'resendCsrf' => $this->csrf->token('verify_resend'),
            'hasToken' => is_string($this->session->get('_verify_token_hash')),
            'flash' => $this->flash->pull(),
        ]));
    }

    public function verify(Request $request): Response
    {
        if (!$this->csrf->verify('verify_email', $request->input('_csrf'))) {
            return Response::html('The verification form expired.', 419);
        }

        $hash = $this->session->get('_verify_token_hash');
        $this->session->remove('_verify_token_hash');
        $verified = is_string($hash) && $this->registration->verify(
            $hash,
            $request->clientIp(),
            (string) $request->attribute('request_id', ''),
        );
        $this->flash->set(
            $verified ? 'success' : 'warning',
            $verified ? 'Your email is verified. You can now log in.' : 'That verification link is invalid or expired.',
        );

        return Response::redirect('/verify-email');
    }

    public function resend(Request $request): Response
    {
        if (!$this->csrf->verify('verify_resend', $request->input('_csrf'))) {
            return Response::html('The resend form expired.', 419);
        }

        $allowed = $this->registration->resend(
            (string) $request->input('email', ''),
            $request->clientIp(),
            (string) $request->attribute('request_id', ''),
        );
        $this->flash->set(
            $allowed ? 'success' : 'warning',
            $allowed ? 'If the account needs verification, a new message is ready.' : 'Too many attempts. Wait before trying again.',
        );

        return Response::redirect('/verify-email');
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, string> $old
     */
    private function renderRegister(array $errors = [], int $status = 200, array $old = []): Response
    {
        return Response::html($this->view->render('identity/register', [
            'pageTitle' => 'Create an account — N3',
            'metaDescription' => 'Create and verify your N3 member account.',
            'registrationEnabled' => $this->config->registrationEnabled,
            'csrf' => $this->csrf->token('register'),
            'errors' => $errors,
            'old' => $old,
            'flash' => $this->flash->pull(),
        ]), $status);
    }
}
