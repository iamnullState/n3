<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Http\JsonBody;
use N3\Http\Request;
use N3\Http\Response;
use N3\Service\AccountService;
use N3\Service\DomainException;

final class AccountController
{
    public function __construct(private readonly AccountService $accounts) {}

    public function update(Request $request): never
    {
        $data = JsonBody::decode($request);
        $user = \currentUser();
        $userId = (int)$user['id'];
        try {
            $result = $this->accounts->changeCredentials(
                $userId,
                (int)$user['session_version'],
                (string)($data['current_password'] ?? ''),
                $data['username'] ?? '',
                (string)($data['new_password'] ?? ''),
            );
            Response::json([
                'username' => $result['username'],
                'csrfToken' => $this->rotateOwnerSession($result['session_version']),
            ])->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    public function invalidateSessions(Request $request): never
    {
        $data = JsonBody::decode($request);
        $user = \currentUser();
        try {
            $nextVersion = $this->accounts->invalidateOtherSessions(
                (int)$user['id'],
                (int)$user['session_version'],
                (string)($data['current_password'] ?? ''),
            );
            Response::json(['csrfToken' => $this->rotateOwnerSession($nextVersion)])->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    private function rotateOwnerSession(int $sessionVersion): string
    {
        $_SESSION['session_version'] = $sessionVersion;
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
