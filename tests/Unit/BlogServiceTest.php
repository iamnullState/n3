<?php

declare(strict_types=1);

namespace N3\Tests\Unit;

use N3\Module\Blog\BlogPost;
use N3\Module\Blog\BlogRepository;
use N3\Module\Blog\BlogService;
use N3\Module\Blog\BlogValidator;
use PHPUnit\Framework\TestCase;

final class BlogServiceTest extends TestCase
{
    public function testDraftInputIsValidatedAndNormalized(): void
    {
        $repository = new InMemoryBlogRepository();
        $service = new BlogService(new BlogValidator(), $repository);
        $invalid = $service->createDraft('', 'Not Valid', str_repeat('x', 501), "bad\0body", 7, '0123456789abcdef');
        self::assertArrayHasKey('title', $invalid->errors);
        self::assertArrayHasKey('slug', $invalid->errors);
        self::assertArrayHasKey('excerpt', $invalid->errors);
        self::assertArrayHasKey('body', $invalid->errors);
        self::assertSame([], $repository->created);

        $created = $service->createDraft('  Hello Blog  ', '  FIRST-POST ', ' Summary ', 'Body', 7, '0123456789abcdef');
        self::assertTrue($created->succeeded());
        self::assertSame('Hello Blog', $repository->created['title']);
        self::assertSame('first-post', $repository->created['slug']);
        self::assertSame('Summary', $repository->created['excerpt']);
    }

    public function testPublishingRequiresBodyAndTransitionsUseExactVersions(): void
    {
        $repository = new InMemoryBlogRepository();
        $repository->posts[] = self::post(1, 'draft', '', 1);
        $service = new BlogService(new BlogValidator(), $repository);
        self::assertArrayHasKey('body', $service->publish(1, 7, 1, '')->errors);

        $repository->posts[0] = self::post(1, 'draft', 'Publish me', 1);
        self::assertTrue($service->publish(1, 7, 1, '')->succeeded());
        self::assertSame(['draft', 'published', 1], $repository->transitioned);
        $repository->transitionResult = false;
        self::assertTrue($service->unpublish(1, 7, 1, '')->conflict);
    }

    public function testPublicListingIsFixedSizeAndBounded(): void
    {
        $repository = new InMemoryBlogRepository();
        for ($id = 1; $id <= 23; ++$id) { $repository->posts[] = self::post($id, 'published', 'Body', 1); }
        $service = new BlogService(new BlogValidator(), $repository);
        $page = $service->listing(3);
        self::assertNotNull($page);
        self::assertSame(3, $page->page);
        self::assertSame(3, $page->totalPages);
        self::assertCount(3, $page->posts);
        self::assertNull($service->listing(4));
        self::assertNull($service->listing(1001));
        self::assertSame([BlogService::PAGE_SIZE, 20], $repository->pagination);
    }

    private static function post(int $id, string $status, string $body, int $version): BlogPost
    {
        return new BlogPost(
            $id, 'Post ' . $id, 'post-' . $id, '', $body, $status, 7, 7, $version,
            $status === 'published' ? '2026-09-02 00:00:00.000000' : null,
            '2026-09-02 00:00:00.000000', '2026-09-02 00:00:00.000000',
        );
    }
}

final class InMemoryBlogRepository implements BlogRepository
{
    /** @var list<BlogPost> */ public array $posts = [];
    /** @var array<string, mixed> */ public array $created = [];
    /** @var array{string, string, int}|array{} */ public array $transitioned = [];
    /** @var array{int, int}|array{} */ public array $pagination = [];
    public bool $transitionResult = true;

    public function listForAdministration(): array { return $this->posts; }
    public function findById(int $id): ?BlogPost { foreach ($this->posts as $post) { if ($post->id === $id) { return $post; } } return null; }
    public function findPublishedBySlug(string $slug): ?BlogPost { foreach ($this->posts as $post) { if ($post->slug === $slug && $post->status === 'published') { return $post; } } return null; }
    public function countPublished(): int { return count(array_filter($this->posts, static fn (BlogPost $post): bool => $post->status === 'published')); }
    public function listPublished(int $limit, int $offset): array { $this->pagination = [$limit, $offset]; return array_slice(array_values(array_filter($this->posts, static fn (BlogPost $post): bool => $post->status === 'published')), $offset, $limit); }
    public function createDraft(string $title, string $slug, string $excerpt, string $body, int $actorId, string $requestId): int { $this->created = compact('title', 'slug', 'excerpt', 'body', 'actorId', 'requestId'); return 1; }
    public function updateDraft(int $id, string $title, string $slug, string $excerpt, string $body, int $actorId, int $expectedVersion, string $requestId): bool { return true; }
    public function transition(int $id, string $from, string $to, int $actorId, int $expectedVersion, string $requestId): bool { $this->transitioned = [$from, $to, $expectedVersion]; return $this->transitionResult; }
}
