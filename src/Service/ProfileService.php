<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\ProfileRepository;

final class ProfileService
{
    public function __construct(
        private readonly ProfileRepository $profiles,
        private readonly ?AccessService $access = null,
        private readonly ?int $viewerUserId = null,
        private readonly ?PluginContributionService $pluginContributions = null,
    ) {
        if ($viewerUserId !== null && $access === null) {
            throw new \InvalidArgumentException('Signed-in profile views require an access context.');
        }
    }

    public function viewBySlug(string $slug): ?array
    {
        $profile = $this->profiles->findBySlug($slug);
        if ($profile === null) return null;
        $profileUserId = (int)$profile['id'];
        $self = $this->viewerUserId !== null && $this->viewerUserId === $profileUserId;
        $visibility = (string)$profile['profile_visibility'];
        if (!$self && $this->viewerUserId === null && $visibility !== 'public') return null;
        if (!$self && $this->viewerUserId !== null && $visibility === 'private') return null;

        $summary = [
            'id' => $profileUserId,
            'username' => (string)$profile['username'],
            'display_name' => trim((string)$profile['display_name']) ?: (string)$profile['username'],
            'biography' => (string)$profile['biography'],
            'profile_slug' => (string)$profile['profile_slug'],
            'profile_visibility' => $visibility,
            'has_avatar' => is_string($profile['avatar_reference']) && trim($profile['avatar_reference']) !== '',
            'audience' => $self ? 'self' : ($this->viewerUserId === null ? 'public' : 'signed_in'),
            'is_self' => $self,
        ];

        if ($self) {
            $accessible = $this->access?->accessiblePageIds(false) ?? [];
            $summary['pages'] = [
                'owned' => $this->withUrls($this->profiles->ownedPages($profileUserId), 'editor'),
                'shared' => $this->withUrls($this->profiles->sharedPages($profileUserId, $accessible), 'editor'),
                'published' => $this->withUrls($this->profiles->publishedPages($profileUserId), 'editor'),
            ];
        } elseif ($this->viewerUserId !== null) {
            $visible = $this->access?->profileVisiblePageIds($profileUserId) ?? [];
            $accessible = $this->access?->accessiblePageIds(false) ?? [];
            $summary['pages'] = ['authored' => $this->withUrls($this->profiles->authoredPages($profileUserId, $visible), 'viewer', $accessible)];
        } else {
            $summary['pages'] = ['published' => $this->withUrls($this->profiles->publishedPages($profileUserId), 'public')];
        }

        $summary['counts'] = array_map('count', $summary['pages']);
        if ($this->pluginContributions !== null && $summary['audience'] !== 'public') {
            $summary['plugin_contributions'] = $this->pluginContributions->forProfile($summary);
        }
        return $summary;
    }

    private function withUrls(array $pages, string $mode, array $accessiblePageIds = []): array
    {
        $accessible = array_fill_keys(array_map('intval', $accessiblePageIds), true);
        foreach ($pages as &$page) {
            $editor = $mode === 'editor' || ($mode === 'viewer' && isset($accessible[(int)$page['id']]));
            $page['url'] = ($editor ? '/page/' : '/p/') . rawurlencode((string)$page['slug']);
            if ($mode === 'public') unset($page['id']);
        }
        return $pages;
    }
}
