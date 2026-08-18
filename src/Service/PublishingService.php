<?php
declare(strict_types=1);

namespace N3\Service;

use N3\Repository\PublicPageRepository;

final class PublishingService
{
    public function __construct(
        private readonly PublicPageRepository $pages,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function visibilityFor(array $page, mixed $requested): ?int
    {
        if (($page['kind'] ?? '') !== 'page') return null;
        return (int)$requested === 1 ? 1 : 0;
    }

    public function content(string $html): string
    {
        $html = $this->sanitizer->clean($html);
        return preg_replace_callback('#href=(["\'])/page/([a-z0-9-]+)\1#i', function (array $matches): string {
            $slug = $this->pages->publishedSlugForEditorTarget($matches[2]);
            return $slug === null ? '' : 'href=' . $matches[1] . '/p/' . rawurlencode($slug) . $matches[1];
        }, $html) ?? $html;
    }
}
