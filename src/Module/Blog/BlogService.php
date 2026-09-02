<?php

declare(strict_types=1);

namespace N3\Module\Blog;

use PDOException;

final readonly class BlogService
{
    public const PAGE_SIZE = 10;
    public const MAXIMUM_PAGE = 1000;

    public function __construct(private BlogValidator $validator, private BlogRepository $posts)
    {
    }

    /** @return list<BlogPost> */
    public function listForAdministration(): array
    {
        return $this->posts->listForAdministration();
    }

    public function find(int $id): ?BlogPost
    {
        return $id > 0 ? $this->posts->findById($id) : null;
    }

    public function findPublished(string $slug): ?BlogPost
    {
        $normalized = $this->validator->normalizeSlug($slug);
        if ($slug !== $normalized || isset($this->validator->errors('valid', $normalized, '', '')['slug'])) {
            return null;
        }

        return $this->posts->findPublishedBySlug($normalized);
    }

    public function listing(int $page): ?BlogListing
    {
        if ($page < 1 || $page > self::MAXIMUM_PAGE) {
            return null;
        }
        $total = $this->posts->countPublished();
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
        if ($page > $totalPages) {
            return null;
        }

        return new BlogListing(
            $this->posts->listPublished(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE),
            $page,
            $totalPages,
            $total,
        );
    }

    public function createDraft(
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        string $requestId,
    ): BlogMutationOutcome {
        $errors = $this->validator->errors($title, $slug, $excerpt, $body);
        if ($errors !== []) {
            return new BlogMutationOutcome($errors);
        }
        try {
            $id = $this->posts->createDraft(
                trim($title), $this->validator->normalizeSlug($slug), trim($excerpt), $body, $actorId, $requestId,
            );
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return new BlogMutationOutcome(['slug' => 'That Blog slug is already in use.']);
            }
            throw $exception;
        }

        return new BlogMutationOutcome(postId: $id);
    }

    public function updateDraft(
        int $id,
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): BlogMutationOutcome {
        $errors = $this->validator->errors($title, $slug, $excerpt, $body);
        if ($errors !== []) {
            return new BlogMutationOutcome($errors, $id);
        }
        try {
            $updated = $this->posts->updateDraft(
                $id, trim($title), $this->validator->normalizeSlug($slug), trim($excerpt), $body,
                $actorId, $expectedVersion, $requestId,
            );
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return new BlogMutationOutcome(['slug' => 'That Blog slug is already in use.'], $id);
            }
            throw $exception;
        }

        return new BlogMutationOutcome(postId: $id, conflict: !$updated);
    }

    public function publish(int $id, int $actorId, int $expectedVersion, string $requestId): BlogMutationOutcome
    {
        $post = $this->find($id);
        if ($post === null) {
            return new BlogMutationOutcome(['form' => 'Blog post not found.']);
        }
        $errors = $this->validator->errors($post->title, $post->slug, $post->excerpt, $post->body, true);
        if ($errors !== []) {
            return new BlogMutationOutcome($errors, $id);
        }

        return $this->transition($id, 'draft', 'published', $actorId, $expectedVersion, $requestId);
    }

    public function unpublish(int $id, int $actorId, int $expectedVersion, string $requestId): BlogMutationOutcome
    {
        return $this->transition($id, 'published', 'draft', $actorId, $expectedVersion, $requestId);
    }

    private function transition(
        int $id,
        string $from,
        string $to,
        int $actorId,
        int $expectedVersion,
        string $requestId,
    ): BlogMutationOutcome {
        $updated = $this->posts->transition($id, $from, $to, $actorId, $expectedVersion, $requestId);

        return new BlogMutationOutcome(postId: $id, conflict: !$updated);
    }
}
