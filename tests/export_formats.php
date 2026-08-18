<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Service\HtmlSanitizer;
use N3\Service\MarkdownExportService;
use N3\View\ViewRenderer;

function verifyExport(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$raw = <<<'HTML'
<h2>Résumé 東京</h2>
<p>Read the <a href="/page/42">internal page</a> or <a href="https://example.com/über?q=one&amp;lang=日本語">external guide</a>.</p>
<div class="callout callout-purple" onclick="alert(1)"><span class="callout-icon">✦</span><div><strong>Worth noting</strong><p>Unicode survives: café, naïve, 🚀.</p></div></div>
<table><thead><tr><th>Region</th><th>Value | note</th></tr></thead><tbody><tr><td>東京</td><td><strong>42</strong></td></tr><tr><td>Québec</td><td>café</td></tr></tbody></table>
<ol><li>Parent one<ul><li>Nested alpha</li><li>Nested beta<ol><li>Deep item</li></ol></li></ul></li><li>Parent two</li></ol>
HTML;

$content = (new HtmlSanitizer())->clean($raw);
$markdown = (new MarkdownExportService())->convert($content);

verifyExport(str_contains($markdown, "## Résumé 東京"), 'Markdown export preserves Unicode headings');
verifyExport(str_contains($markdown, '[internal page](/page/42)') && str_contains($markdown, '[external guide](https://example.com/%C3%BCber?q=one&lang=%E6%97%A5%E6%9C%AC%E8%AA%9E)'), 'Markdown export preserves internal and external links');
verifyExport(str_contains($markdown, "> ✦ **Worth noting**") && str_contains($markdown, '> Unicode survives: café, naïve, 🚀.'), 'Markdown export preserves callouts as quoted content');
verifyExport(str_contains($markdown, '| Region | Value \\| note |') && str_contains($markdown, '| 東京 | **42** |') && str_contains($markdown, '| Québec | café |'), 'Markdown export preserves complex tables');
verifyExport(str_contains($markdown, "1. Parent one\n  - Nested alpha\n  - Nested beta\n    1. Deep item\n2. Parent two"), 'Markdown export preserves ordered and nested list structure');

$views = new ViewRenderer(dirname(__DIR__) . '/views');
$html = $views->render('page/export', ['page' => [
    'title' => 'Résumé 東京 <unsafe>',
    'content' => $content,
]]);
verifyExport(str_contains($html, '<meta charset="utf-8">') && str_contains($html, '<title>Résumé 東京 &lt;unsafe&gt;</title>'), 'HTML export declares UTF-8 and escapes its title');
verifyExport(str_contains($html, '<table>') && str_contains($html, '<div class="callout callout-purple">') && str_contains($html, '<ol><li>Parent one<ul>'), 'HTML export preserves tables, callouts, and nested lists');
verifyExport(str_contains($html, 'https://example.com/%C3%BCber?q=one&amp;lang=%E6%97%A5%E6%9C%AC%E8%AA%9E') && str_contains($html, 'Unicode survives: café, naïve, 🚀.'), 'HTML export preserves links and Unicode content');
verifyExport(!str_contains($html, 'onclick='), 'HTML export receives sanitized rich content');

echo "\nn3 export format test passed.\n";
