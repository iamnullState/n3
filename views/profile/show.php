<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$public = $profile['audience'] === 'public';
$self = (bool)$profile['is_self'];
$displayName = (string)$profile['display_name'];
$description = mb_substr(trim(preg_replace('/\s+/', ' ', (string)$profile['biography']) ?? ''), 0, 180);
if ($description === '') $description = $displayName . ' on ' . $appName . '.';
$documentTitle = $displayName . ' · ' . $appName;
$head = '<meta name="description" content="' . $escape($description) . '">';
if ($public) {
    $head .= '<meta property="og:type" content="profile"><meta property="og:title" content="' . $escape($displayName) . '"><meta property="og:description" content="' . $escape($description) . '"><meta property="og:url" content="' . $escape($canonical) . '"><link rel="canonical" href="' . $escape($canonical) . '">';
} else {
    $head .= '<meta name="robots" content="noindex,nofollow">';
}
$headerLinks = $profile['audience'] === 'public'
    ? '<nav><a href="/public">Public knowledge</a><a href="/login">Sign in</a></nav>'
    : '<nav><a href="/dashboard">Dashboard</a><a href="/public">Public knowledge</a></nav>';
$header = '<a href="' . ($public ? '/public' : '/dashboard') . '" class="public-brand" aria-label="' . $escape($appName) . ' home"><span>n3</span></a><div class="public-header-actions">' . $headerLinks . '</div>';
$pluginContributions = !$public && is_array($profile['plugin_contributions'] ?? null) ? $profile['plugin_contributions'] : [];
$pluginTools = is_array($pluginContributions['tools'] ?? null) ? $pluginContributions['tools'] : [];
$pluginCards = is_array($pluginContributions['cards'] ?? null) ? $pluginContributions['cards'] : [];

$groupLabels = $self
    ? [
        'owned' => ['Owned pages', 'Pages in spaces you own, including collaborator work.'],
        'shared' => ['Shared with me', 'Pages you can open in spaces owned by someone else.'],
        'published' => ['Published by me', 'Public pages you authored; these may also appear above.'],
    ]
    : ($public
        ? ['published' => ['Published pages', 'Public writing from this profile.']]
        : ['authored' => ['Pages you can view', 'Public pages and private collaboration you can already access.']]);

$renderPages = static function (array $pages) use ($escape): string {
    if ($pages === []) return '<div class="profile-empty">Nothing to show here yet.</div>';
    $cards = '';
    foreach ($pages as $page) {
        $dateValue = (string)($page['updated_at'] ?? '');
        $timestamp = strtotime($dateValue);
        $dateLabel = $timestamp === false ? 'Date unavailable' : 'Updated ' . gmdate('M j, Y', $timestamp);
        $status = (int)$page['is_public'] === 1 ? 'Public' : 'Private collaboration';
        $cards .= '<a class="profile-page-card" href="' . $escape($page['url']) . '"><div><h3>' . $escape($page['title']) . '</h3><span>' . $escape($dateLabel) . '</span></div><span class="profile-page-status">' . $escape($status) . '</span></a>';
    }
    return $cards;
};

ob_start();
?>
<main class="profile-page">
  <section class="profile-hero">
    <div class="profile-page-avatar<?= $profile['has_avatar'] ? ' has-avatar' : '' ?>">
      <?php if ($profile['has_avatar']): ?><img src="/avatar/<?= rawurlencode($profile['profile_slug']) ?>" alt="<?= $escape($displayName) ?> avatar"><?php endif; ?>
      <span aria-hidden="true"><?= $escape(mb_strtoupper(mb_substr($displayName, 0, 1)) ?: '?') ?></span>
    </div>
    <div class="profile-identity">
      <span class="eyebrow"><?= $self ? 'YOUR PROFILE' : 'PROFILE' ?></span>
      <h1><?= $escape($displayName) ?></h1>
      <div class="profile-handle">@<?= $escape($profile['username']) ?></div>
      <?php if ($profile['biography'] !== ''): ?><p><?= nl2br($escape($profile['biography'])) ?></p><?php endif; ?>
      <div class="profile-hero-meta"><span><?= $escape(ucfirst($profile['profile_visibility'])) ?> profile</span><?php if ($self): ?><a href="/dashboard">Profile settings</a><?php endif; ?><?php foreach ($pluginTools as $tool): ?><a href="<?= $escape($tool['url'] ?? '') ?>"><?= $escape($tool['label'] ?? '') ?></a><?php endforeach; ?></div>
    </div>
  </section>
  <?php if ($pluginCards !== []): ?>
    <section class="profile-plugin-cards" aria-labelledby="profile-plugin-cards-heading">
      <div class="profile-group-heading"><div><span class="eyebrow">PROFILE TOOLS</span><h2 id="profile-plugin-cards-heading">Plugin cards</h2></div></div>
      <div class="profile-plugin-card-list">
        <?php foreach ($pluginCards as $card): $cardBody = '<span class="eyebrow">' . $escape($card['plugin_name'] ?? 'Plugin') . '</span><strong>' . $escape($card['title'] ?? '') . '</strong><small>' . $escape($card['body'] ?? '') . '</small>'; ?>
          <?php if (!empty($card['url'])): ?><a class="profile-plugin-card" href="<?= $escape($card['url']) ?>"><?= $cardBody ?></a><?php else: ?><div class="profile-plugin-card"><?= $cardBody ?></div><?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
  <div class="profile-groups">
    <?php foreach ($groupLabels as $key => [$label, $copy]): $pages = $profile['pages'][$key] ?? []; ?>
      <section class="profile-group" aria-labelledby="profile-group-<?= $escape($key) ?>">
        <div class="profile-group-heading"><div><h2 id="profile-group-<?= $escape($key) ?>"><?= $escape($label) ?></h2><p><?= $escape($copy) ?></p></div><span aria-label="<?= count($pages) ?> pages"><?= count($pages) ?></span></div>
        <div class="profile-page-list"><?= $renderPages($pages) ?></div>
      </section>
    <?php endforeach; ?>
  </div>
</main>
<?php
$body = (string)ob_get_clean();
require dirname(__DIR__) . '/layouts/public.php';
