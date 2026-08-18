<?php
declare(strict_types=1);

namespace N3\Controller;

use N3\Config;
use N3\Http\Response;
use N3\Repository\PublicPageRepository;

final class SyndicationController
{
    public function __construct(private readonly PublicPageRepository $pages) {}

    public function sitemap(): never
    {
        $urls = '<url><loc>' . $this->xml($this->url('/public')) . '</loc></url>'
            . '<url><loc>' . $this->xml($this->url('/tags')) . '</loc></url>';
        foreach ($this->pages->published() as $page) {
            $urls .= '<url><loc>' . $this->xml($this->url('/p/' . rawurlencode($page['slug']))) . '</loc>'
                . '<lastmod>' . gmdate(DATE_ATOM, strtotime($page['updated_at'] . ' UTC')) . '</lastmod></url>';
        }
        $body = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . $urls . '</urlset>';
        (new Response($body, 200, ['Content-Type' => 'application/xml; charset=utf-8']))->send();
    }

    public function feed(): never
    {
        $name = Config::appName();
        $items = '';
        foreach ($this->pages->published() as $page) {
            $url = $this->url('/p/' . rawurlencode($page['slug']));
            $items .= '<item><title>' . $this->xml($page['title']) . '</title><link>' . $this->xml($url) . '</link>'
                . '<guid>' . $this->xml($url) . '</guid><description>' . $this->xml($this->description($page['content'])) . '</description>'
                . '<pubDate>' . gmdate(DATE_RSS, strtotime($page['updated_at'] . ' UTC')) . '</pubDate></item>';
        }
        $body = '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . $this->xml($name) . '</title>'
            . '<link>' . $this->xml($this->url('/public')) . '</link><description>Published pages from ' . $this->xml($name) . '</description>'
            . $items . '</channel></rss>';
        (new Response($body, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']))->send();
    }

    private function url(string $path): string
    {
        return Config::appUrl() . '/' . ltrim($path, '/');
    }

    private function description(string $html): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? ''), 0, 180);
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
