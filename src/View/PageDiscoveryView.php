<?php
declare(strict_types=1);

namespace N3\View;

final class PageDiscoveryView
{
    public static function render(array $references, array $related, bool $public): string
    {
        if ($references === [] && $related === []) return '';
        $escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
        $referenceItems = '';
        foreach ($references as $reference) {
            $referenceItems .= '<li><a href="' . $escape($reference['url']) . '">' . $escape($reference['label']) . '</a><small>' . $escape($reference['url']) . '</small></li>';
        }
        $relatedItems = '';
        foreach ($related as $page) {
            $url = ($public ? '/p/' : '/page/') . rawurlencode((string)$page['slug']);
            $relatedItems .= '<li><a href="' . $escape($url) . '">' . $escape($page['title']) . '</a><small>' . (int)$page['shared_tags'] . ' shared ' . ((int)$page['shared_tags'] === 1 ? 'tag' : 'tags') . '</small></li>';
        }
        return '<section class="page-discovery"><div><h2>References</h2>' . ($referenceItems !== '' ? '<ol class="reference-list">' . $referenceItems . '</ol>' : '<p class="discovery-empty">No references listed.</p>') . '</div><div><h2>Similar pages</h2>' . ($relatedItems !== '' ? '<ul class="similar-list">' . $relatedItems . '</ul>' : '<p class="discovery-empty">Add shared tags to connect related pages.</p>') . '</div></section>';
    }
}
