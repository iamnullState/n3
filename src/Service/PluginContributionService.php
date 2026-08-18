<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Plugin\PluginRegistry;

final class PluginContributionService
{
    public function __construct(private readonly PluginRegistry $plugins) {}

    public function forProfile(array $profile): array
    {
        $audience = (string)($profile['audience'] ?? 'public');
        if (!in_array($audience, ['self', 'signed_in'], true)) return ['tools' => [], 'cards' => []];
        $slug = (string)($profile['profile_slug'] ?? '');
        $context = [
            'surface' => 'profile',
            'audience' => $audience,
            'profile' => [
                'display_name' => (string)($profile['display_name'] ?? ''),
                'profile_url' => $slug === '' ? null : '/u/' . rawurlencode($slug),
                'avatar_url' => !empty($profile['has_avatar']) && $slug !== '' ? '/avatar/' . rawurlencode($slug) : null,
                'visibility' => (string)($profile['profile_visibility'] ?? 'private'),
                'is_self' => (bool)($profile['is_self'] ?? false),
                'page_counts' => $this->counts($profile['counts'] ?? []),
            ],
        ];
        return [
            'tools' => $this->plugins->profileTools($context),
            'cards' => $this->plugins->profileCards($context),
        ];
    }

    public function pageInformationRows(array $page, array $information, array $permissions): array
    {
        $slug = (string)($page['slug'] ?? '');
        $author = is_array($information['author'] ?? null) ? $information['author'] : [];
        return $this->plugins->pageInformationRows([
            'surface' => 'page_information',
            'audience' => 'authenticated',
            'page' => [
                'title' => (string)($page['title'] ?? ''),
                'page_url' => $slug === '' ? null : '/page/' . rawurlencode($slug),
                'is_public' => (bool)($page['is_public'] ?? false),
                'can_edit' => (bool)($permissions['can_edit'] ?? false),
                'can_manage' => (bool)($permissions['can_manage'] ?? false),
            ],
            'information' => [
                'author' => [
                    'state' => (string)($author['state'] ?? 'unknown'),
                    'name' => (string)($author['name'] ?? 'Unknown author'),
                    'profile_url' => is_string($author['profile_url'] ?? null) ? $author['profile_url'] : null,
                    'avatar_url' => is_string($author['avatar_url'] ?? null) ? $author['avatar_url'] : null,
                ],
                'word_count' => (int)($information['word_count'] ?? 0),
                'created_at' => is_string($information['created_at'] ?? null) ? $information['created_at'] : null,
                'first_published_at' => is_string($information['first_published_at'] ?? null) ? $information['first_published_at'] : null,
                'updated_at' => is_string($information['updated_at'] ?? null) ? $information['updated_at'] : null,
            ],
        ]);
    }

    private function counts(mixed $counts): array
    {
        if (!is_array($counts)) return [];
        $allowed = ['owned', 'shared', 'published', 'authored'];
        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $counts)) $result[$key] = max(0, (int)$counts[$key]);
        }
        return $result;
    }
}
