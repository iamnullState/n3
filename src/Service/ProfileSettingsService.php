<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\ProfileRepository;
use PDOException;

final class ProfileSettingsService
{
    private const VISIBILITIES = ['private', 'members', 'public'];

    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly AccountService $accounts,
    ) {}

    public function settings(int $userId): array
    {
        $settings = $this->profiles->settingsForUser($userId);
        if ($settings === null) throw new DomainException('Profile not found.', 404);
        return $this->project($settings);
    }

    public function update(int $userId, int $sessionVersion, array $input): array
    {
        $current = $this->profiles->settingsForUser($userId);
        if ($current === null) throw new DomainException('Profile not found.', 404);

        $username = trim((string)($input['username'] ?? ''));
        $displayName = trim((string)($input['display_name'] ?? ''));
        $biography = trim((string)($input['biography'] ?? ''));
        $visibility = (string)($input['profile_visibility'] ?? '');
        if ($username === '') throw new DomainException('Username is required.', 422);
        if (mb_strlen($username) > 80) throw new DomainException('Keep the username to 80 characters or fewer.', 422);
        if (mb_strlen($displayName) > 80) throw new DomainException('Keep the display name to 80 characters or fewer.', 422);
        if (mb_strlen($biography) > 1000) throw new DomainException('Keep the biography to 1000 characters or fewer.', 422);
        if (!in_array($visibility, self::VISIBILITIES, true)) throw new DomainException('Choose a valid profile visibility.', 422);

        $usernameChanged = $username !== (string)$current['username'];
        if ($usernameChanged && !password_verify((string)($input['current_password'] ?? ''), $this->accounts->passwordHash($userId))) {
            throw new DomainException('Current password is incorrect.', 403);
        }
        $nextSessionVersion = $usernameChanged ? $sessionVersion + 1 : $sessionVersion;
        try {
            $updated = $this->profiles->updateSettings(
                $userId,
                $sessionVersion,
                $username,
                $displayName,
                $biography,
                $visibility,
                $nextSessionVersion,
            );
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') throw new DomainException('That username is unavailable.', 409);
            throw $error;
        }
        if (!$updated) throw new DomainException('The profile changed in another session. Refresh and try again.', 409);
        $settings = $this->profiles->settingsForUser($userId);
        if ($settings === null) throw new DomainException('Profile not found.', 404);
        return $this->project($settings) + ['session_version' => $nextSessionVersion, 'session_rotated' => $usernameChanged];
    }

    private function project(array $settings): array
    {
        $slug = (string)$settings['profile_slug'];
        $hasAvatar = is_string($settings['avatar_reference']) && $settings['avatar_reference'] !== '';
        return [
            'username' => (string)$settings['username'],
            'display_name' => (string)$settings['display_name'],
            'biography' => (string)$settings['biography'],
            'profile_slug' => $slug,
            'profile_visibility' => (string)$settings['profile_visibility'],
            'profile_url' => '/u/' . rawurlencode($slug),
            'has_avatar' => $hasAvatar,
            'avatar_url' => $hasAvatar ? '/avatar/' . rawurlencode($slug) : null,
        ];
    }
}
