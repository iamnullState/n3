<?php

declare(strict_types=1);

namespace N3\App\Content;

final class PageValidator
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

        if (mb_strlen($title, 'UTF-8') < 1 || mb_strlen($title, 'UTF-8') > 200) {
            $errors['title'] = 'Title must be between 1 and 200 characters.';
        }
        if (strlen($slug) < 1 || strlen($slug) > 160 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            $errors['slug'] = 'Slug must use lowercase letters, numbers, and single hyphens.';
        }
        if (mb_strlen($excerpt, 'UTF-8') > 500) {
            $errors['excerpt'] = 'Excerpt must not exceed 500 characters.';
        }
        if (mb_strlen($body, 'UTF-8') > 100_000 || str_contains($body, "\0")) {
            $errors['body'] = 'Body must not exceed 100,000 characters.';
        } elseif ($publishing && trim($body) === '') {
            $errors['body'] = 'Add page content before publishing.';
        }

        return $errors;
    }
}
