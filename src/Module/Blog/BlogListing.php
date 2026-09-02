<?php

declare(strict_types=1);

namespace N3\Module\Blog;

final readonly class BlogListing
{
    /** @param list<BlogPost> $posts */
    public function __construct(
        public array $posts,
        public int $page,
        public int $totalPages,
        public int $totalPosts,
    ) {
    }
}
