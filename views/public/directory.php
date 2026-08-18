<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$byId = [];
foreach ($nodes as $node) $byId[(int)$node['id']] = $node;
$visible = [];
foreach ($nodes as $node) {
    if ($node['kind'] !== 'page' || !(int)$node['is_public']) continue;
    $cursor = $node;
    $seen = [];
    while ($cursor && !isset($seen[(int)$cursor['id']])) {
        $id = (int)$cursor['id'];
        $seen[$id] = true;
        $visible[$id] = true;
        $cursor = $cursor['parent_id'] === null ? null : ($byId[(int)$cursor['parent_id']] ?? null);
    }
}

$renderNodes = function (int $spaceId, ?int $parentId, array $trail = []) use (&$renderNodes, $nodes, $visible, $currentSlug, $escape): string {
    $html = '';
    foreach ($nodes as $node) {
        $id = (int)$node['id'];
        $nodeParent = $node['parent_id'] === null ? null : (int)$node['parent_id'];
        if ((int)$node['space_id'] !== $spaceId || $nodeParent !== $parentId || !isset($visible[$id]) || isset($trail[$id])) continue;
        if ($node['kind'] === 'page' && !(int)$node['is_public']) {
            $html .= $renderNodes($spaceId, $id, $trail + [$id => true]);
            continue;
        }
        if ($node['kind'] === 'page') {
            $active = $currentSlug !== null && hash_equals((string)$node['slug'], $currentSlug) ? ' active' : '';
            $html .= '<a class="public-directory-page' . $active . '" href="/p/' . rawurlencode($node['slug']) . '"><span>◇</span>' . $escape($node['title']) . '</a>';
            continue;
        }
        $children = $renderNodes($spaceId, $id, $trail + [$id => true]);
        if ($children !== '') $html .= '<details open><summary><span>▰</span>' . $escape($node['title']) . '</summary><div>' . $children . '</div></details>';
    }
    return $html;
};

$directory = '';
foreach ($spaces as $space) {
    $children = $renderNodes((int)$space['id'], null);
    if ($children === '') continue;
    $color = preg_match('/^#[0-9a-f]{6}$/i', $space['color']) ? $space['color'] : '#415a77';
    $directory .= '<details class="public-directory-space" open><summary><span class="space-dot" style="background:' . $color . '"></span>' . $escape($space['name']) . '</summary><div>' . $children . '</div></details>';
}
if ($directory === '') $directory = '<p class="public-directory-empty">No published pages yet.</p>';
?>
<aside class="public-directory" id="publicDirectory" aria-label="Published page directory"><div class="public-directory-heading"><span class="eyebrow">DIRECTORY</span><span class="public-directory-heading-actions"><a href="/public">All pages</a><button class="public-directory-close" type="button" aria-label="Close directory">&times;</button></span></div><nav><?= $directory ?></nav></aside><button class="public-directory-scrim" type="button" aria-label="Close directory" tabindex="-1"></button>
