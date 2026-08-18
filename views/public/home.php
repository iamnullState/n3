<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$cards = '';
foreach ($pages as $page) {
    $pageTags = '';
    foreach (array_filter(explode('||', (string)$page['tags'])) as $pageTag) {
        $pageTags .= '<span>' . $escape($pageTag) . '</span>';
    }
    $excerpt = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags($page['content'])) ?? ''), 0, 220);
    $cards .= '<a class="public-card" href="/p/' . rawurlencode($page['slug']) . '"><h2>' . $escape($page['title']) . '</h2><p>' . $escape($excerpt) . '</p><div class="public-tags">' . $pageTags . '</div></a>';
}
$filtered = $query !== '' || $tag !== '';
if ($cards === '') $cards = '<div class="public-empty">' . ($filtered ? 'No public pages match those filters.' : 'No public pages have been published yet.') . '</div>';
$filterNote = $tag === '' ? '' : '<div class="public-filter-note">Showing tag <strong>' . $escape($tag) . '</strong> · <a href="/public">Clear filter</a></div>';
$documentTitle = $appName;
$header = '<a href="/public" class="public-brand" aria-label="' . $escape($appName) . ' home"><span>n3</span></a><div class="public-header-actions"><nav><a href="/tags">Browse tags</a><a href="/login">Owner sign in</a></nav><button class="public-directory-toggle" type="button" aria-controls="publicDirectory" aria-expanded="false"><span aria-hidden="true">☰</span>Directory</button></div>';
ob_start();
?>
<div class="public-layout"><?= $directory ?><main class="public-main"><div class="public-hero"><span class="eyebrow">PUBLIC KNOWLEDGE</span><h1><?= $escape($appName) ?></h1><p>Published notes, ideas, and useful references.</p><form class="public-search" role="search" method="get" action="/public"><label class="visually-hidden" for="publicSearch">Search published pages</label><input id="publicSearch" type="search" name="q" value="<?= $escape($query) ?>" placeholder="Search public pages…" maxlength="100"><button type="submit">Search</button></form><?= $filterNote ?></div><div class="public-grid"><?= $cards ?></div></main></div>
<?php
$body = (string)ob_get_clean();
require dirname(__DIR__) . '/layouts/public.php';
