<?php
declare(strict_types=1);

namespace N3\Service;

use DOMDocument;
use DOMElement;

final class HtmlSanitizer
{
    private const ALLOWED_TAGS = '<p><br><h1><h2><h3><h4><ul><ol><li><blockquote><pre><code><strong><b><em><i><u><s><font><a><img><video><hr><table><thead><tbody><tr><th><td><div><span><details><summary><input>';
    private const ALLOWED_CLASSES = ['lead', 'callout', 'callout-purple', 'callout-blue', 'callout-icon', 'feature-grid', 'feature-card', 'media-float-left', 'media-float-right', 'media-column', 'media-center', 'media-size-25', 'media-size-50', 'media-size-75', 'media-size-100'];

    public function clean(string $html): string
    {
        $html = mb_substr(strip_tags($html, self::ALLOWED_TAGS), 0, 2_000_000);
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="n3-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('n3-root');
        if (!$root) return '<p></p>';

        $elements = [];
        foreach ($root->getElementsByTagName('*') as $element) $elements[] = $element;
        foreach ($elements as $element) {
            if (!$element instanceof DOMElement) continue;
            $this->cleanElement($element);
        }

        $clean = '';
        foreach ($root->childNodes as $child) $clean .= $document->saveHTML($child);
        return $clean;
    }

    private function cleanElement(DOMElement $element): void
    {
        $tag = strtolower($element->tagName);
        $class = $element->getAttribute('class');
        $title = $element->getAttribute('title');
        $alt = $element->getAttribute('alt');
        $href = $element->getAttribute('href');
        $src = $element->getAttribute('src');
        $color = strtolower(trim($element->getAttribute('color')));
        $checked = $element->hasAttribute('checked');
        $controls = $element->hasAttribute('controls');
        $attributes = [];
        foreach ($element->attributes as $attribute) $attributes[] = $attribute->name;
        foreach ($attributes as $attribute) $element->removeAttribute($attribute);

        if ($class !== '') {
            $classes = array_intersect(preg_split('/\s+/', trim($class)) ?: [], self::ALLOWED_CLASSES);
            if ($classes) $element->setAttribute('class', implode(' ', $classes));
        }
        if ($title !== '') $element->setAttribute('title', mb_substr($title, 0, 500));
        if ($alt !== '') $element->setAttribute('alt', mb_substr($alt, 0, 500));
        if ($tag === 'a' && $href !== '' && preg_match('~^(https?://|mailto:|/|#)~i', trim($href))) {
            $element->setAttribute('href', trim($href));
        }
        if ($tag === 'font' && preg_match('/^#[0-9a-f]{6}$/D', $color)) $element->setAttribute('color', $color);
        if ($tag === 'img' && $src !== '') {
            $url = trim($src);
            if (preg_match('#^https?://#i', $url) || preg_match('#^/media/[a-f0-9]{40}\.(?:jpg|png|gif|webp|avif|bmp)$#i', $url) || preg_match('#^data:image/(?:png|gif|jpeg|webp);base64,#i', $url)) {
                $element->setAttribute('src', $url);
            }
        }
        if ($tag === 'video' && $src !== '') {
            $url = trim($src);
            if (preg_match('#^(?:https?://|/media/[a-f0-9]{40}\.mp4$)#i', $url)) $element->setAttribute('src', $url);
            if ($controls) $element->setAttribute('controls', 'controls');
            $element->setAttribute('preload', 'metadata');
        }
        if ($tag === 'input') {
            $element->setAttribute('type', 'checkbox');
            $element->setAttribute('disabled', 'disabled');
            if ($checked) $element->setAttribute('checked', 'checked');
        }
    }
}
