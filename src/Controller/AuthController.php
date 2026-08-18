<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Http\Request;
use N3\Http\Response;
use N3\Service\AuthService;
use N3\Service\AppSettingsService;
use N3\Config;
use N3\Service\DomainException;
use PDOException;

final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ?AppSettingsService $settings = null,
    ) {}

    public function setup(Request $request): void
    {
        if ($this->auth->accountExists()) Response::redirect('/login')->send();
        if ($request->method() === 'GET') \renderAuthPage('setup');
        if ($request->method() !== 'POST') return;

        \verifyCsrf();
        $username = \cleanText($_POST['username'] ?? '', 80);
        $password = (string)($_POST['password'] ?? '');
        $confirmation = (string)($_POST['password_confirm'] ?? '');
        if ($username === '') \renderAuthPage('setup', 'Choose a username.', 422);
        if (mb_strlen($password) < 12) \renderAuthPage('setup', 'Use at least 12 characters for your password.', 422);
        if (!hash_equals($password, $confirmation)) \renderAuthPage('setup', 'The passwords do not match.', 422);

        if ($this->settings !== null) {
            try {
                $settings = $this->settings->update([
                    'brand_name' => $_POST['brand_name'] ?? 'n3',
                    'tailscale_ip' => $_POST['tailscale_ip'] ?? '',
                    'port' => $_POST['port'] ?? 8786,
                    'app_url' => $_POST['app_url'] ?? '',
                ]);
                foreach (['icon', 'banner'] as $kind) {
                    if (($_FILES[$kind]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        $settings = $this->settings->storeBrandAsset($kind, $_FILES[$kind]);
                    }
                }
                Config::setRuntimeSettings($settings);
            } catch (DomainException $error) {
                \renderAuthPage('setup', $error->getMessage(), $error->status());
            }
        }

        try {
            $userId = $this->auth->createOwner($username, $password);
            if ($userId === null) Response::redirect('/login')->send();
            $this->startOwnerSession($userId, 1);
            Response::redirect('/dashboard')->send();
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') \renderAuthPage('setup', 'That username is unavailable.', 422);
            throw $error;
        }
    }

    public function login(Request $request): void
    {
        if (\currentUser()) Response::redirect('/dashboard')->send();
        if (!$this->auth->accountExists()) Response::redirect('/setup')->send();
        if ($request->method() === 'GET') \renderAuthPage('login');
        if ($request->method() !== 'POST') return;

        \verifyCsrf();
        $ip = mb_substr(\requestClientIp(), 0, 64);
        if ($this->auth->isRateLimited($ip)) \renderAuthPage('login', 'Too many attempts. Try again in 15 minutes.', 429);
        $username = \cleanText($_POST['username'] ?? '', 80);
        $password = (string)($_POST['password'] ?? '');
        $user = $this->auth->findByUsername($username);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            $this->auth->recordFailedLogin($ip);
            usleep(300000);
            \renderAuthPage('login', 'The username or password is incorrect.', 401);
        }

        $this->auth->clearFailedLogins($ip);
        $this->startOwnerSession((int)$user['id'], (int)$user['session_version']);
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->auth->rehashPassword((int)$user['id'], $password);
        }
        Response::redirect('/dashboard')->send();
    }

    public function logout(): never
    {
        if (!\currentUser()) Response::json(['ok' => true])->send();
        \verifyCsrf();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        Response::json(['ok' => true])->send();
    }

    private function startOwnerSession(int $userId, int $sessionVersion): void
    {
        $_SESSION['user_id'] = $userId;
        $_SESSION['session_version'] = $sessionVersion;
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
