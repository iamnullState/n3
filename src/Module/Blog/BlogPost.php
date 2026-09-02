<?php

declare(strict_types=1);

namespace N3\Module\Blog;

final readonly class BlogPost
{
    public function __construct(
        public int $id,
        public string $title,
        public string $slug,
        public string $excerpt,
        public string $body,
        public string $status,
        public int $authorId,
        public int $updatedBy,
        public int $lockVersion,
        public ?string $publishedAt,
        public string $createdAt,
        public string $updatedAt,
    ) {
    }
}
