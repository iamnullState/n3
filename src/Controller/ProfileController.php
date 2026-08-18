<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Http\JsonBody;
use N3\Http\Request;
use N3\Http\Response;
use N3\Service\DomainException;
use N3\Service\ProfileAvatarService;
use N3\Service\ProfileSettingsService;

final class ProfileController
{
    public function __construct(
        private readonly ProfileSettingsService $settings,
        private readonly ProfileAvatarService $avatars,
    ) {}

    public function show(array $user): never
    {
        try {
            Response::json($this->settings->settings((int)$user['id']))->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    public function update(Request $request, array $user): never
    {
        try {
            $result = $this->settings->update(
                (int)$user['id'],
                (int)$user['session_version'],
                JsonBody::decode($request),
            );
            if ($result['session_rotated']) $result['csrfToken'] = $this->rotateSession((int)$result['session_version']);
            unset($result['session_version'], $result['session_rotated']);
            Response::json($result)->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    public function storeAvatar(array $user, array $upload): never
    {
        try {
            $avatar = $this->avatars->storeForUser((int)$user['id'], $upload);
            $settings = $this->settings->settings((int)$user['id']);
            Response::json($avatar + ['avatar_url' => $settings['avatar_url']], 201)->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    public function removeAvatar(array $user): never
    {
        try {
            $this->avatars->removeForUser((int)$user['id']);
            Response::json(['ok' => true, 'has_avatar' => false, 'avatar_url' => null])->send();
        } catch (DomainException $error) {
            Response::json(['error' => $error->getMessage()], $error->status())->send();
        }
    }

    private function rotateSession(int $sessionVersion): string
    {
        $_SESSION['session_version'] = $sessionVersion;
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return $_SESSION['csrf'];
    }
}
