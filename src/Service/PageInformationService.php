<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\ProfileRepository;

final class PageInformationService
{
    public function __construct(private readonly ProfileRepository $profiles) {}

    public function forPage(array $page, ?int $viewerUserId): array
    {
        return [
            'author' => $this->author($page['author_id'] ?? null, $viewerUserId),
            'word_count' => $this->wordCount((string)($page['content'] ?? '')),
            'created_at' => $this->date($page['created_at'] ?? null),
            'first_published_at' => $this->date($page['first_published_at'] ?? null),
            'updated_at' => $this->date($page['updated_at'] ?? null),
        ];
    }

    private function author(mixed $authorId, ?int $viewerUserId): array
    {
        if ($authorId === null || (int)$authorId < 1) return $this->fallbackAuthor('unknown', 'Unknown author');
        $profile = $this->profiles->findByUserId((int)$authorId);
        if ($profile === null) return $this->fallbackAuthor('unknown', 'Unknown author');

        $visibility = (string)$profile['profile_visibility'];
        $visible = $viewerUserId === (int)$profile['id']
            || ($viewerUserId !== null && in_array($visibility, ['members', 'public'], true))
            || ($viewerUserId === null && $visibility === 'public');
        if (!$visible) return $this->fallbackAuthor('private', 'Private author');

        $name = trim((string)$profile['display_name']) ?: (string)$profile['username'];
        $slug = (string)$profile['profile_slug'];
        $hasAvatar = is_string($profile['avatar_reference']) && trim($profile['avatar_reference']) !== '';
        return [
            'state' => 'visible',
            'name' => $name,
            'profile_url' => '/u/' . rawurlencode($slug),
            'avatar_url' => $hasAvatar ? '/avatar/' . rawurlencode($slug) : null,
        ];
    }

    private function fallbackAuthor(string $state, string $name): array
    {
        return ['state' => $state, 'name' => $name, 'profile_url' => null, 'avatar_url' => null];
    }

    private function wordCount(string $html): int
    {
        $text = preg_replace('/<[^>]*>/', ' ', $html) ?? '';
        $text = trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($text === '') return 0;
        return count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function date(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return null;
        $trimmed = trim($value);
        $timestamp = strtotime(preg_match('/(?:Z|[+-]\d\d:\d\d)$/iD', $trimmed) ? $trimmed : $trimmed . ' UTC');
        if ($timestamp === false) return null;
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}
