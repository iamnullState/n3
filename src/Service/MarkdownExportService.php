<?php
declare(strict_types=1);

namespace N3\Service;

use DOMDocument;
use DOMElement;
use DOMNode;

final class MarkdownExportService
{
    public function convert(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><div id="n3-export-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('n3-export-root');
        if (!$root) return "\n";

        $markdown = $this->renderChildren($root);
        $markdown = preg_replace('/[ \t]+\n/u', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{3,}/u', "\n\n", $markdown) ?? $markdown;
        return trim($markdown) . "\n";
    }

    private function renderChildren(DOMNode $node): string
    {
        $output = '';
        foreach ($node->childNodes as $child) $output .= $this->renderNode($child);
        return $output;
    }

    private function renderNode(DOMNode $node): string
    {
        if ($node->nodeType === XML_TEXT_NODE) {
            $value = $node->nodeValue ?? '';
            if (trim($value) === '') return '';
            return preg_replace('/\s+/u', ' ', $value) ?? '';
        }
        if (!$node instanceof DOMElement) return '';

        $tag = strtolower($node->tagName);
        $content = $this->renderChildren($node);

        if (preg_match('/^h([1-6])$/', $tag, $matches)) {
            return str_repeat('#', (int)$matches[1]) . ' ' . trim($content) . "\n\n";
        }

        return match ($tag) {
            'p' => trim($content) === '' ? '' : trim($content) . "\n\n",
            'br' => "\n",
            'strong', 'b' => '**' . trim($content) . '**',
            'em', 'i' => '*' . trim($content) . '*',
            's' => '~~' . trim($content) . '~~',
            'a' => $this->renderLink($node, $content),
            'img' => $this->renderImage($node),
            'video' => $this->renderVideo($node),
            'code' => $node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'pre'
                ? $node->textContent
                : $this->renderInlineCode($node->textContent),
            'pre' => $this->renderCodeBlock($node),
            'blockquote' => $this->renderQuote($content),
            'ul', 'ol' => $this->renderList($node),
            'table' => $this->renderTable($node),
            'hr' => "---\n\n",
            'input' => $node->hasAttribute('checked') ? '[x] ' : '[ ] ',
            'span' => $this->hasClass($node, 'callout-icon') ? trim($content) . ' ' : $content,
            'div' => $this->hasClass($node, 'callout') ? $this->renderCallout($node) : $content . "\n",
            'details' => trim($content) . "\n\n",
            'summary' => '**' . trim($content) . "**\n\n",
            default => $content,
        };
    }

    private function renderLink(DOMElement $node, string $content): string
    {
        $label = trim($content);
        $href = trim($node->getAttribute('href'));
        if ($href === '') return $label;
        return '[' . $label . '](' . str_replace([' ', '(', ')'], ['%20', '%28', '%29'], $href) . ')';
    }

    private function renderImage(DOMElement $node): string
    {
        $src = trim($node->getAttribute('src'));
        if ($src === '') return '';
        return '![' . str_replace([']', "\n"], ['\\]', ' '], $node->getAttribute('alt')) . '](' . $src . ')';
    }

    private function renderVideo(DOMElement $node): string
    {
        $src = trim($node->getAttribute('src'));
        return $src === '' ? '' : '[Video](' . $src . ')';
    }

    private function renderInlineCode(string $content): string
    {
        $delimiter = str_contains($content, '`') ? '``' : '`';
        return $delimiter . trim($content) . $delimiter;
    }

    private function renderCodeBlock(DOMElement $node): string
    {
        return "```\n" . rtrim($node->textContent) . "\n```\n\n";
    }

    private function renderQuote(string $content): string
    {
        $content = trim($content);
        if ($content === '') return '';
        $content = preg_replace('/\n{3,}/u', "\n\n", $content) ?? $content;
        return implode("\n", array_map(
            static fn(string $line): string => $line === '' ? '>' : '> ' . $line,
            explode("\n", $content)
        )) . "\n\n";
    }

    private function renderCallout(DOMElement $callout): string
    {
        $heading = '';
        foreach ($callout->getElementsByTagName('span') as $span) {
            if ($span instanceof DOMElement && $this->hasClass($span, 'callout-icon')) {
                $heading = trim($span->textContent);
                break;
            }
        }
        foreach ($callout->getElementsByTagName('strong') as $strong) {
            $heading = trim($heading . ' **' . trim($strong->textContent) . '**');
            break;
        }

        $parts = $heading === '' ? [] : [$heading];
        foreach ($callout->getElementsByTagName('p') as $paragraph) {
            if ($paragraph instanceof DOMElement) $parts[] = trim($this->renderChildren($paragraph));
        }
        return $parts === [] ? $this->renderQuote($this->renderChildren($callout)) : $this->renderQuote(implode("\n\n", $parts));
    }

    private function renderList(DOMElement $list, int $depth = 0): string
    {
        $ordered = strtolower($list->tagName) === 'ol';
        $number = 1;
        $output = '';
        foreach ($list->childNodes as $child) {
            if (!$child instanceof DOMElement || strtolower($child->tagName) !== 'li') continue;

            $body = '';
            $nested = '';
            foreach ($child->childNodes as $itemChild) {
                if ($itemChild instanceof DOMElement && in_array(strtolower($itemChild->tagName), ['ul', 'ol'], true)) {
                    $nested .= $this->renderList($itemChild, $depth + 1);
                } else {
                    $body .= $this->renderNode($itemChild);
                }
            }

            $marker = $ordered ? $number++ . '. ' : '- ';
            $indent = str_repeat('  ', $depth);
            $lines = preg_split('/\n+/u', trim($body)) ?: [''];
            $output .= $indent . $marker . array_shift($lines) . "\n";
            foreach ($lines as $line) $output .= $indent . str_repeat(' ', strlen($marker)) . $line . "\n";
            $output .= $nested;
        }
        return $output . ($depth === 0 ? "\n" : '');
    }

    private function renderTable(DOMElement $table): string
    {
        $rows = [];
        $headerRow = null;
        foreach ($table->getElementsByTagName('tr') as $row) {
            if (!$row instanceof DOMElement) continue;
            $cells = [];
            $hasHeader = false;
            foreach ($row->childNodes as $cell) {
                if (!$cell instanceof DOMElement || !in_array(strtolower($cell->tagName), ['th', 'td'], true)) continue;
                $hasHeader = $hasHeader || strtolower($cell->tagName) === 'th';
                $value = trim($this->renderChildren($cell));
                $value = preg_replace('/\s*\n+\s*/u', '<br>', $value) ?? $value;
                $cells[] = str_replace('|', '\\|', $value);
            }
            if ($cells === []) continue;
            if ($headerRow === null && $hasHeader) $headerRow = count($rows);
            $rows[] = $cells;
        }
        if ($rows === []) return '';

        $columns = max(array_map('count', $rows));
        foreach ($rows as &$row) $row = array_pad($row, $columns, '');
        unset($row);
        if ($headerRow !== null && $headerRow !== 0) {
            $header = $rows[$headerRow];
            array_splice($rows, $headerRow, 1);
            array_unshift($rows, $header);
        }

        $output = '| ' . implode(' | ', $rows[0]) . " |\n";
        $output .= '| ' . implode(' | ', array_fill(0, $columns, '---')) . " |\n";
        foreach (array_slice($rows, 1) as $row) $output .= '| ' . implode(' | ', $row) . " |\n";
        return $output . "\n";
    }

    private function hasClass(DOMElement $node, string $class): bool
    {
        return in_array($class, preg_split('/\s+/', trim($node->getAttribute('class'))) ?: [], true);
    }
}
