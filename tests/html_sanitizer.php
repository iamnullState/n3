<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/autoload.php';

use N3\Service\HtmlSanitizer;

function verifySanitizer(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

$sanitizer = new HtmlSanitizer();

$scripts = $sanitizer->clean('<p onclick="alert(1)">Safe<script>alert(1)</script></p>');
verifySanitizer($scripts === '<p>Safealert(1)</p>', 'sanitizer removes disallowed elements and event attributes');

$links = $sanitizer->clean('<a href="javascript:alert(1)" target="_blank">Bad</a><a href="https://example.com">Web</a><a href="mailto:test@example.com">Mail</a><a href="/page/2">Internal</a><a href="#section">Anchor</a>');
verifySanitizer(!str_contains($links, 'javascript:') && !str_contains($links, 'target='), 'sanitizer strips unsafe link protocols and unrelated attributes');
verifySanitizer(str_contains($links, 'href="https://example.com"') && str_contains($links, 'href="/page/2"') && str_contains($links, 'href="#section"'), 'sanitizer preserves supported external, internal, and fragment links');

$classes = $sanitizer->clean('<div class="callout unknown lead" style="color:red"><span class="callout-icon bad">Note</span></div>');
verifySanitizer(str_contains($classes, 'class="callout lead"') && str_contains($classes, 'class="callout-icon"') && !str_contains($classes, 'style='), 'sanitizer keeps only presentation classes from the allowlist');

$images = $sanitizer->clean('<img src="data:image/png;base64,AAAA" alt="Preview"><img src="data:text/html;base64,AAAA"><img src="javascript:bad">');
verifySanitizer(str_contains($images, 'src="data:image/png;base64,AAAA"') && !str_contains($images, 'data:text/html') && !str_contains($images, 'javascript:'), 'sanitizer allows supported image data while rejecting active sources');

$mediaName = str_repeat('a', 40);
$media = $sanitizer->clean('<img class="media-float-left unknown" src="/media/' . $mediaName . '.webp"><video class="media-float-right" src="/media/' . $mediaName . '.mp4" controls autoplay onplay="bad()"></video><video src="javascript:bad" controls></video>');
verifySanitizer(str_contains($media, 'class="media-float-left"') && str_contains($media, '/media/' . $mediaName . '.webp'), 'sanitizer preserves uploaded photos and their safe alignment');
verifySanitizer(
    str_contains($sanitizer->clean('<img class="media-size-25 media-size-50 media-size-75 media-size-100" src="/media/' . $mediaName . '.webp">'), 'class="media-size-25 media-size-50 media-size-75 media-size-100"'),
    'sanitizer preserves the bounded media resizing classes',
);
verifySanitizer(
    $sanitizer->clean('<font color="#a1b2c3">safe</font><font color="red" style="position:fixed">plain</font><hr>') === '<font color="#a1b2c3">safe</font><font>plain</font><hr>',
    'sanitizer preserves safe text colors and dividers while removing unsafe presentation',
);
verifySanitizer(str_contains($media, 'class="media-float-right"') && str_contains($media, '/media/' . $mediaName . '.mp4') && str_contains($media, 'controls') && str_contains($media, 'preload="metadata"'), 'sanitizer preserves uploaded MP4 playback');
verifySanitizer(!str_contains($media, 'autoplay') && !str_contains($media, 'onplay') && !str_contains($media, 'javascript:'), 'sanitizer removes unsafe video behavior and sources');

$checkbox = $sanitizer->clean('<input type="text" value="bad" checked>');
verifySanitizer(str_contains($checkbox, 'type="checkbox"') && str_contains($checkbox, 'disabled') && str_contains($checkbox, 'checked') && !str_contains($checkbox, 'value='), 'sanitizer normalizes task inputs to disabled checkboxes');

echo "\nn3 HTML sanitizer test passed.\n";
