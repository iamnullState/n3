<?php

declare(strict_types=1);

namespace N3\App\Content;

use N3\Core\Database\TransactionManager;
use PDOException;

final readonly class PageService
{
    public function __construct(
        private PageValidator $validator,
        private PageRepository $pages,
        private ContentEventRecorder $events,
        private TransactionManager $transactions,
    ) {
    }

    /** @return list<Page> */
    public function listForAdministration(): array
    {
        return $this->pages->listForAdministration();
    }

    public function find(int $id): ?Page
    {
        return $this->pages->findById($id);
    }

    public function findPublished(string $slug): ?Page
    {
        $normalized = $this->validator->normalizeSlug($slug);
        if ($slug !== $normalized || ($this->validator->errors('valid', $normalized, '', '')['slug'] ?? null)) {
            return null;
        }

        return $this->pages->findPublishedBySlug($normalized);
    }

    public function createDraft(
        string $title,
        string $slug,
        string $excerpt,
        string $body,
        int $actorId,
        string $requestId,
    ): PageMutationOutcome {
        $errors = $this->validator->errors($title, $slug, $excerpt, $body);
        if ($errors !== []) {
            return new PageMutationOutcome($errors);
        }
        $slug = $this->validator->normalizeSlug($slug);

        try {
            $id = $this->transactions->run(function () use ($title, $slug, $excerpt, $body, $actorId, $requestId): int {
                $id = $this->pages->createDraft(trim($title), $slug, trim($excerpt), $body, $actorId);
                $this->events->record($id, $actorId, 'created', null, 'draft', $requestId);

                return $id;
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return new PageMutationOutcome(['slug' => 'That slug is already in use.']);
            }
            throw $exception;
        }

        return new PageMutationOutcome(pageId: $id);
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
    ): PageMutationOutcome {
        $errors = $this->validator->errors($title, $slug, $excerpt, $body);
        if ($errors !== []) {
            return new PageMutationOutcome($errors, $id);
        }
        $slug = $this->validator->normalizeSlug($slug);

        try {
            $updated = $this->transactions->run(function () use ($id, $title, $slug, $excerpt, $body, $actorId, $expectedVersion, $requestId): bool {
                $updated = $this->pages->updateDraft(
                    $id,
                    trim($title),
                    $slug,
                    trim($excerpt),
                    $body,
                    $actorId,
                    $expectedVersion,
                );
                if ($updated) {
                    $this->events->record($id, $actorId, 'updated', 'draft', 'draft', $requestId);
                }

                return $updated;
            });
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                return new PageMutationOutcome(['slug' => 'That slug is already in use.'], $id);
            }
            throw $exception;
        }

        return new PageMutationOutcome(pageId: $id, conflict: !$updated);
    }

    public function publish(int $id, int $actorId, int $expectedVersion, string $requestId): PageMutationOutcome
    {
        $page = $this->pages->findById($id);
        if ($page === null) {
            return new PageMutationOutcome(['form' => 'Page not found.']);
        }
        $errors = $this->validator->errors($page->title, $page->slug, $page->excerpt, $page->body, true);
        if ($errors !== []) {
            return new PageMutationOutcome($errors, $id);
        }

        return $this->transition($id, 'draft', 'published', $actorId, $expectedVersion, $requestId);
    }

    public function unpublish(int $id, int $actorId, int $expectedVersion, string $requestId): PageMutationOutcome
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
    ): PageMutationOutcome {
        $updated = $this->transactions->run(function () use ($id, $from, $to, $actorId, $expectedVersion, $requestId): bool {
            $updated = $this->pages->transition($id, $from, $to, $actorId, $expectedVersion);
            if ($updated) {
                $this->events->record($id, $actorId, $to === 'published' ? 'published' : 'unpublished', $from, $to, $requestId);
            }

            return $updated;
        });

        return new PageMutationOutcome(pageId: $id, conflict: !$updated);
    }
}
