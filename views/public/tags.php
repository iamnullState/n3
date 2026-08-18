<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$items = '';
foreach ($tags as $tag) {
    $count = (int)$tag['page_count'];
    $items .= '<a class="public-tag-card" href="/public?tag=' . rawurlencode($tag['name']) . '"><strong>' . $escape($tag['name']) . '</strong><span>' . $count . ($count === 1 ? ' page' : ' pages') . '</span></a>';
}
if ($items === '') $items = '<div class="public-empty">No tags are attached to public pages yet.</div>';
$documentTitle = 'Tags · ' . $appName;
$header = '<a href="/public" class="public-brand" aria-label="' . $escape($appName) . ' home"><span>n3</span></a><div class="public-header-actions"><a href="/public">All public pages</a><button class="public-directory-toggle" type="button" aria-controls="publicDirectory" aria-expanded="false"><span aria-hidden="true">☰</span>Directory</button></div>';
ob_start();
?>
<div class="public-layout"><?= $directory ?><main class="public-main"><div class="public-hero"><span class="eyebrow">BROWSE</span><h1>Public tags</h1><p>Explore published pages by topic.</p></div><div class="public-tag-grid"><?= $items ?></div></main></div>
<?php
$body = (string)ob_get_clean();
require dirname(__DIR__) . '/layouts/public.php';
