<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$documentTitle = $page['title'] . ' · Preview';
$header = '<a href="/page/' . rawurlencode((string)$page['slug']) . '" class="public-brand"><span>n3</span>Back to editor</a><strong>' . $escape($visibility) . '</strong>';
$featureImage = $featureImage ?? null;
$featureImages = new \N3\Service\FeatureImageService();
$featureClass = $featureImage === null ? '' : ' has-feature-image';
$featureMarkup = $featureImage === null ? '' : '<div class="feature-image" aria-hidden="true"></div>';
ob_start();
?>
<main class="public-article<?= $featureClass ?>"<?= $featureImages->styleAttribute($featureImage) ?>><?= $featureMarkup ?><h1><?= $escape($page['title']) ?></h1><article class="prose"><?= $content ?></article><?= \N3\View\PageDiscoveryView::render($references ?? [], $related ?? [], false) ?><?= \N3\View\PageInformationView::render($pageInformation ?? []) ?></main>
<?php
$body = (string)ob_get_clean();
require dirname(__DIR__) . '/layouts/public.php';
