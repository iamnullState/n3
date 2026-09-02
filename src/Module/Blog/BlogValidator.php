<?php

declare(strict_types=1);

namespace N3\Module\Blog;

final class BlogValidator
{
    public function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    /** @return array<string, string> */
    public function errors(string $title, string $slug, string $excerpt, string $body, bool $publishing = false): array
    {
        $errors = [];
        $title = trim($title);
        $slug = $this->normalizeSlug($slug);
        $excerpt = trim($excerpt);

        if (!mb_check_encoding($title, 'UTF-8') || mb_strlen($title, 'UTF-8') < 1 || mb_strlen($title, 'UTF-8') > 200
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $title) === 1) {
            $errors['title'] = 'Title must be between 1 and 200 characters without control characters.';
        }
        if (strlen($slug) < 1 || strlen($slug) > 160 || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            $errors['slug'] = 'Slug must use lowercase letters, numbers, and single hyphens.';
        }
        if (!mb_check_encoding($excerpt, 'UTF-8') || mb_strlen($excerpt, 'UTF-8') > 500
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $excerpt) === 1) {
            $errors['excerpt'] = 'Excerpt must not exceed 500 characters or contain control characters.';
        }
        if (!mb_check_encoding($body, 'UTF-8') || mb_strlen($body, 'UTF-8') > 100_000 || str_contains($body, "\0")) {
            $errors['body'] = 'Body must be valid text no longer than 100,000 characters.';
        } elseif ($publishing && trim($body) === '') {
            $errors['body'] = 'Add post content before publishing.';
        }

        return $errors;
    }
}
