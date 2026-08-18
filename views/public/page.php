<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$tagLinks = '';
foreach ($tags as $tag) {
    $tagLinks .= '<a href="/public?tag=' . rawurlencode($tag) . '">' . $escape($tag) . '</a>';
}
$featureImage = $featureImage ?? null;
$featureImages = new \N3\Service\FeatureImageService();
$featureClass = $featureImage === null ? '' : ' has-feature-image';
$featureMarkup = $featureImage === null ? '' : '<div class="feature-image" aria-hidden="true"></div>';
$documentTitle = $page['title'] . ' · ' . $appName;
$head = '<meta name="description" content="' . $escape($description) . '"><meta property="og:type" content="article"><meta property="og:title" content="' . $escape($page['title']) . '"><meta property="og:description" content="' . $escape($description) . '"><meta property="og:url" content="' . $escape($canonical) . '">';
if ($featureImage !== null) {
    $head .= '<meta property="og:image" content="' . $escape(rtrim(\N3\Config::appUrl(), '/') . $featureImage['url']) . '">';
}
$head .= '<link rel="canonical" href="' . $escape($canonical) . '">';
$header = '<a href="/public" class="public-brand" aria-label="' . $escape($appName) . ' home"><span>n3</span></a><div class="public-header-actions"><a href="/public">All public pages</a><button class="public-directory-toggle" type="button" aria-controls="publicDirectory" aria-expanded="false"><span aria-hidden="true">☰</span>Directory</button></div>';
ob_start();
?>
<div class="public-layout"><?= $directory ?><main class="public-article<?= $featureClass ?>"<?= $featureImages->styleAttribute($featureImage) ?>><?= $featureMarkup ?><div class="public-tags"><?= $tagLinks ?></div><h1><?= $escape($page['title']) ?></h1><article class="prose"><?= $content ?></article><?= \N3\View\PageDiscoveryView::render($references ?? [], $related ?? [], true) ?><?= \N3\View\PageInformationView::render($pageInformation ?? []) ?></main></div>
<?php
$body = (string)ob_get_clean();
require dirname(__DIR__) . '/layouts/public.php';
