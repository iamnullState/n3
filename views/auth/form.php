<?php
declare(strict_types=1);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$settings = is_array($applicationSettings ?? null) ? $applicationSettings : [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <title><?= $escape($title) ?> · <?= $escape($appName) ?></title>
  <style>
    :root{font-family:Inter,ui-sans-serif,system-ui,sans-serif;color:#0d1b2a;background:#e0e1dd;--surface:#f3f4f1;--line:#b3bcc6;--accent:#415a77;--strong:#1b263b;--on-accent:#e0e1dd;--muted:#415a77;--error:#9c3f57;--error-soft:#ecd7dc;--shadow:#a5b1bd}*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:28px;background:radial-gradient(circle at 16% 12%,var(--error-soft),transparent 28%),radial-gradient(circle at 85% 80%,#c8d0da,transparent 32%),#e0e1dd}.card{width:min(100%,440px);padding:42px;border:1px solid var(--line);border-bottom-width:8px;border-radius:9px;background:var(--surface);box-shadow:0 12px 0 color-mix(in srgb,var(--shadow),transparent 18%),0 24px 54px rgba(13,27,42,.18)}.card.setup{width:min(100%,760px)}.mark{display:grid;place-items:center;width:52px;height:52px;margin-bottom:31px;overflow:hidden;border:1px solid var(--strong);border-radius:7px;color:var(--on-accent);background:var(--accent);box-shadow:0 6px 0 var(--strong);font-weight:900}.mark img{width:100%;height:100%;object-fit:cover}h1{margin:0 0 10px;font-size:clamp(34px,8vw,48px);line-height:.98;letter-spacing:-.06em}p{margin:0 0 29px;color:var(--muted);line-height:1.55}.setup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 22px}.section-title{grid-column:1/-1;margin:28px 0 0;padding-top:22px;border-top:3px dotted var(--line)}.section-title h2{margin:0;font-size:17px}.section-title p{margin:5px 0 0;font-size:12px}label{display:block;margin:19px 0 0;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase}input{width:100%;margin-top:8px;padding:13px 14px;border:1px solid var(--line);border-bottom-width:3px;border-radius:6px;background:#f8f9f7;color:inherit;font:500 15px inherit;outline:none}input[type=file]{padding:9px;font-size:12px}input:focus{border-color:var(--strong);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent),transparent 65%)}.hint{display:block;margin-top:6px;color:var(--muted);font-size:10px;font-weight:600;letter-spacing:0;line-height:1.4;text-transform:none}button{width:100%;margin-top:27px;padding:13px;border:1px solid var(--strong);border-radius:7px;background:var(--accent);color:var(--on-accent);box-shadow:0 7px 0 var(--strong);font-weight:900;letter-spacing:.05em;text-transform:uppercase;cursor:pointer}.error{padding:13px 14px;border:3px solid var(--error);border-left-width:8px;border-radius:4px;color:#6d2438;background:var(--error-soft);font-weight:700}.note{margin:24px 0 0;padding-top:18px;border-top:3px dotted var(--line);font-size:11px;font-weight:700;text-align:center}@media(max-width:620px){.card,.card.setup{padding:28px}.setup-grid{grid-template-columns:1fr}.section-title{grid-column:auto}}@media(prefers-color-scheme:dark){:root{color:#e0e1dd;background:#0d1b2a;--surface:#1b263b;--line:#2b3a4f;--accent:#778da9;--strong:#a8bfd8;--on-accent:#0d1b2a;--muted:#a9b8c9;--error:#e58fa2;--error-soft:#3b2530;--shadow:#060e17}body{background:radial-gradient(circle at 16% 12%,#3b2530,transparent 30%),radial-gradient(circle at 85% 80%,#1b263b,transparent 34%),#0d1b2a}input{background:#101f2f}.error{color:#f4c9d3}}@media(prefers-reduced-motion:reduce){*{transition:none!important}}
  </style>
</head>
<body>
<main class="card<?= $setup ? ' setup' : '' ?>">
  <div class="mark"><?php if (($settings['iconUrl'] ?? null) !== null): ?><img src="<?= $escape($settings['iconUrl']) ?>" alt=""><?php else: ?>n3<?php endif; ?></div>
  <h1><?= $escape($title) ?></h1>
  <p><?= $escape($copy) ?></p>
  <?php if ($error !== ''): ?><div class="error" role="alert"><?= $escape($error) ?></div><?php endif; ?>
  <form method="post" action="/<?= $escape($mode) ?>"<?= $setup ? ' enctype="multipart/form-data"' : '' ?>>
    <input type="hidden" name="csrf" value="<?= $escape($token) ?>">
    <div class="setup-grid">
      <?php if ($setup): ?>
        <div class="section-title"><h2>Brand &amp; address</h2><p>Make this installation yours. You can change these later in Appearance settings.</p></div>
        <label>Brand name<input name="brand_name" value="<?= $escape($settings['brandName'] ?? 'n3') ?>" maxlength="80" required autofocus></label>
        <label>Tailscale IP<input name="tailscale_ip" value="<?= $escape($settings['tailscaleIp'] ?? '') ?>" inputmode="decimal" placeholder="100.x.x.x"><span class="hint">Leave blank when using localhost or a reverse proxy.</span></label>
        <label>Port<input name="port" type="number" min="1" max="65535" value="<?= (int)($settings['port'] ?? 8786) ?>" required></label>
        <label>Public application URL<input name="app_url" type="url" value="<?= $escape($settings['appUrl'] ?? '') ?>" placeholder="http://100.x.x.x:8786"><span class="hint">Used for published links. The Docker bind comes from APP_BIND_IP and APP_PORT.</span></label>
        <label>Brand icon<input name="icon" type="file" accept="image/jpeg,image/png,image/gif,image/webp"><span class="hint">Square images work best. 5 MB maximum.</span></label>
        <label>Brand banner<input name="banner" type="file" accept="image/jpeg,image/png,image/gif,image/webp"><span class="hint">A wide dashboard image. 5 MB maximum.</span></label>
        <div class="section-title"><h2>Administrator</h2><p>Create the owner account for this n3 installation.</p></div>
      <?php endif; ?>
      <label>Username<input name="username" autocomplete="username" maxlength="80" <?= $setup ? '' : 'autofocus' ?> required></label>
      <label>Password<input name="password" type="password" autocomplete="<?= $escape($passwordAutocomplete) ?>" minlength="<?= (int)$passwordMinlength ?>" maxlength="200" required></label>
      <?php if ($setup): ?><label>Confirm password<input name="password_confirm" type="password" autocomplete="new-password" minlength="12" maxlength="200" required></label><?php endif; ?>
    </div>
    <button type="submit"><?= $escape($button) ?></button>
  </form>
  <p class="note"><?= $escape($appName) ?> keeps your session in a secure, HTTP-only cookie.</p>
</main>
</body>
</html>
