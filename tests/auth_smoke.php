<?php
declare(strict_types=1);

$base = rtrim(getenv('N3_URL') ?: 'http://127.0.0.1', '/');
$cookie = '';
$expectedVersion = trim((string)file_get_contents(dirname(__DIR__) . '/VERSION'));

function call(string $method, string $path, ?array $form = null, array $headers = [], bool $json = false): array
{
    global $base, $cookie;
    $headerLines = $headers;
    if ($cookie !== '') $headerLines[] = "Cookie: $cookie";
    $options = ['http' => [
        'method' => $method,
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
        'header' => implode("\r\n", $headerLines),
    ]];
    if ($form !== null) {
        $contentType = $json ? 'application/json' : 'application/x-www-form-urlencoded';
        $options['http']['header'] .= ($options['http']['header'] ? "\r\n" : '') . "Content-Type: $contentType";
        $options['http']['content'] = $json ? json_encode($form) : http_build_query($form);
    }
    $body = file_get_contents($base . $path, false, stream_context_create($options));
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    foreach ($responseHeaders as $line) {
        if (stripos($line, 'Set-Cookie:') !== 0) continue;
        $pair = trim(explode(';', trim(substr($line, 11)), 2)[0]);
        if (str_starts_with($pair, 'n3_session=')) $cookie = $pair;
    }
    return ['status' => (int)($match[1] ?? 0), 'headers' => $responseHeaders, 'body' => (string)$body];
}

function callMultipart(string $path, string $field, string $filename, string $mime, string $content, array $headers = []): array
{
    global $base, $cookie;
    $boundary = '----n3-' . bin2hex(random_bytes(12));
    $body = "--$boundary\r\n"
        . 'Content-Disposition: form-data; name="' . $field . '"; filename="' . $filename . "\"\r\n"
        . 'Content-Type: ' . $mime . "\r\n\r\n"
        . $content . "\r\n--$boundary--\r\n";
    $headerLines = $headers;
    if ($cookie !== '') $headerLines[] = "Cookie: $cookie";
    $headerLines[] = "Content-Type: multipart/form-data; boundary=$boundary";
    $responseBody = file_get_contents($base . $path, false, stream_context_create(['http' => [
        'method' => 'POST',
        'ignore_errors' => true,
        'follow_location' => 0,
        'timeout' => 5,
        'header' => implode("\r\n", $headerLines),
        'content' => $body,
    ]]));
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return ['status' => (int)($match[1] ?? 0), 'headers' => $responseHeaders, 'body' => (string)$responseBody];
}

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException("Check failed: $message");
    echo "✓ $message\n";
}

function csrfFrom(string $html): string
{
    if (!preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $match)) throw new RuntimeException('CSRF token missing.');
    return $match[1];
}

$health = call('GET', '/api/health');
$healthData = json_decode($health['body'], true);
check($health['status'] === 200 && ($healthData['status'] ?? '') === 'ok' && ($healthData['version'] ?? '') === $expectedVersion, 'health reports status and application version');
check(in_array('X-Content-Type-Options: nosniff', $health['headers'], true), 'security headers are present');
$healthPost = call('POST', '/api/health');
check($healthPost['status'] === 200, 'health preserves its method-independent contract');

$protected = call('GET', '/api/bootstrap');
check($protected['status'] === 401, 'API rejects anonymous requests');
$missingPublicPage = call('GET', '/p/not-a-real-page');
check($missingPublicPage['status'] === 404, 'missing public pages return not found');

$root = call('GET', '/');
check($root['status'] === 200 && str_contains($root['body'], 'No public pages have been published yet.'), 'public home is available before setup');

$setupPage = call('GET', '/setup');
check($setupPage['status'] === 200 && str_contains($setupPage['body'], 'Create your account'), 'setup page is available once');
$anonymousLogout = call('POST', '/logout');
check($anonymousLogout['status'] === 200 && str_contains($anonymousLogout['body'], '"ok":true'), 'anonymous sign out remains idempotent');
$setup = call('POST', '/setup', [
    'csrf' => csrfFrom($setupPage['body']),
    'username' => 'owner',
    'password' => 'correct horse battery staple',
    'password_confirm' => 'correct horse battery staple',
]);
check($setup['status'] === 303 && in_array('Location: /dashboard', $setup['headers'], true), 'account creation signs in to the dashboard');

$bootstrap = call('GET', '/api/bootstrap');
$data = json_decode($bootstrap['body'], true);
check($bootstrap['status'] === 200 && ($data['username'] ?? '') === 'owner', 'authenticated session reaches wiki');
$seededPages = array_values(array_filter($data['pages'] ?? [], static fn(array $page): bool => ($page['kind'] ?? '') === 'page'));
check(
    is_string($data['csrfToken'] ?? null)
        && strlen($data['csrfToken']) === 64
        && $seededPages !== []
        && !in_array('', array_map(static fn(array $page): string => (string)($page['slug'] ?? ''), $seededPages), true),
    'session provides CSRF token and canonical slugs for seeded pages'
);
$rootRedirect = call('GET', '/');
$appShell = call('GET', '/dashboard');
$phpShell = call('GET', '/index.php');
$htmlShell = call('GET', '/index.html');
check(
    $rootRedirect['status'] === 303
        && in_array('Location: /dashboard', $rootRedirect['headers'], true)
        && $appShell['status'] === 200
        && $phpShell['status'] === 200
        && $htmlShell['status'] === 200
        && str_contains($appShell['body'], '<title>n3</title>')
        && str_contains($phpShell['body'], '<title>n3</title>')
        && str_contains($htmlShell['body'], '<title>n3</title>'),
    'authenticated root redirects to the dashboard and application shell keeps supported entry paths'
);

$badMutation = call('POST', '/api/pages', null, ['Content-Type: application/json']);
check($badMutation['status'] === 403, 'mutations require CSRF token');

$csrfHeader = ['X-CSRF-Token: ' . $data['csrfToken']];

$pluginInventory = json_decode(call('GET', '/api/plugins')['body'], true);
check(is_array($pluginInventory['plugins'] ?? null), 'administrator can inspect the plugin inventory when no personal plugins are bundled');
$spaceId = (int)$data['spaces'][0]['id'];
$created = call('POST', '/api/pages', ['space_id' => $spaceId, 'title' => 'Authenticated smoke page'], $csrfHeader, true);
$createdData = json_decode($created['body'], true);
$pageId = (int)($createdData['id'] ?? 0);
check($created['status'] === 201 && $pageId > 0, 'authenticated page creation');
$preview = call('GET', "/preview/$pageId");
check(
    $preview['status'] === 200
        && str_contains($preview['body'], 'Private preview')
        && str_contains($preview['body'], 'Page information')
        && str_contains($preview['body'], 'href="/u/owner-1"')
        && in_array('X-Robots-Tag: noindex, nofollow', $preview['headers'], true),
    'private preview stays authenticated and renders authorized page provenance'
);

$folder = call('POST', '/api/pages', ['space_id' => $spaceId, 'kind' => 'folder', 'title' => 'Test folder'], $csrfHeader, true);
$folderData = json_decode($folder['body'], true);
$folderId = (int)($folderData['id'] ?? 0);
check($folder['status'] === 201 && $folderId > 0, 'folder creation');

$complexExportContent = '<h2>Résumé 東京</h2>'
    . '<p onclick="alert(1)">Protected needle — café 🚀. <a href="/page/' . $pageId . '">Internal page</a> <a href="https://example.com/guide?q=one&amp;lang=ja">External guide</a></p>'
    . '<div class="callout callout-purple"><span class="callout-icon">✦</span><div><strong>Worth noting</strong><p>Callout body with naïve Unicode.</p></div></div>'
    . '<table><thead><tr><th>Region</th><th>Value | note</th></tr></thead><tbody><tr><td>東京</td><td><strong>42</strong></td></tr></tbody></table>'
    . '<ol><li>Parent one<ul><li>Nested alpha</li><li>Nested beta<ol><li>Deep item</li></ol></li></ul></li><li>Parent two</li></ol>'
    . '<a href="javascript:alert(1)">unsafe</a><img src="https://example.com/safe.png" onerror="alert(1)">';
$updated = call('PUT', "/api/pages/$pageId", [
    'title' => 'Authenticated page updated',
    'content' => $complexExportContent,
    'base_revision' => 1,
    'is_public' => 1,
    'tags' => ['Security', 'Reference', 'security'],
    'references' => [
        ['label' => 'External security source', 'url' => 'https://example.com/security'],
        ['label' => 'Stable internal source', 'url' => "/page/$pageId"],
    ],
    'parent_id' => $folderId,
], $csrfHeader, true);
check($updated['status'] === 200, 'authenticated autosave');
$saved = call('GET', "/api/pages/$pageId");
$savedData = json_decode($saved['body'], true);
$slug = (string)($savedData['slug'] ?? '');
$savedInformation = $savedData['page_information'] ?? [];
check(
    $saved['status'] === 200
        && !str_contains(strtolower($saved['body']), 'javascript:')
        && !str_contains(strtolower($saved['body']), 'onclick')
        && !str_contains(strtolower($saved['body']), 'onerror')
        && str_contains($saved['body'], 'https://example.com/safe.png')
        && str_contains($saved['body'], '"is_public":1')
        && str_contains($saved['body'], '"parent_id":' . $folderId)
        && $slug !== ''
        && str_contains($saved['body'], '"tags":["reference","security"]')
        && str_contains($saved['body'], 'External security source')
        && str_contains($saved['body'], 'Stable internal source')
        && str_contains($saved['body'], '<table>')
        && str_contains($saved['body'], 'callout callout-purple')
        && ($savedInformation['author']['state'] ?? '') === 'visible'
        && ($savedInformation['author']['profile_url'] ?? '') === '/u/owner-1'
        && !array_key_exists('username', $savedInformation['author'] ?? [])
        && !array_key_exists('author_id', $savedData)
        && !array_key_exists('last_editor_id', $savedData)
        && (int)($savedInformation['word_count'] ?? 0) > 0
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)($savedInformation['first_published_at'] ?? '')) === 1
        && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', (string)($savedData['updated_at'] ?? '')) === 1,
    'page API returns safe content, relationships, and an authorized provenance projection'
);
$htmlExport = call('GET', "/api/export/$pageId?format=html");
$markdownExport = call('GET', "/api/export/$pageId?format=markdown");
check(
    $htmlExport['status'] === 200
        && str_contains(implode("\n", $htmlExport['headers']), 'Content-Disposition: attachment; filename="authenticated-page-updated.html"')
        && str_contains($htmlExport['body'], '<h1>Authenticated page updated</h1>')
        && str_contains($htmlExport['body'], '<h2>Résumé 東京</h2>')
        && str_contains($htmlExport['body'], '<table>')
        && str_contains($htmlExport['body'], '<div class="callout callout-purple">')
        && str_contains($htmlExport['body'], '<ol><li>Parent one<ul>')
        && $markdownExport['status'] === 200
        && str_contains(implode("\n", $markdownExport['headers']), 'Content-Disposition: attachment; filename="authenticated-page-updated.md"')
        && str_contains($markdownExport['body'], '# Authenticated page updated')
        && str_contains($markdownExport['body'], '## Résumé 東京')
        && str_contains($markdownExport['body'], '> ✦ **Worth noting**')
        && str_contains($markdownExport['body'], '| Region | Value \\| note |')
        && str_contains($markdownExport['body'], '| 東京 | **42** |')
        && str_contains($markdownExport['body'], "1. Parent one\n  - Nested alpha\n  - Nested beta\n    1. Deep item\n2. Parent two")
        && str_contains($markdownExport['body'], '[Internal page](/page/' . $pageId . ')')
        && str_contains($markdownExport['body'], '[External guide](https://example.com/guide?q=one&lang=ja)')
        && str_contains($markdownExport['body'], 'Protected needle — café 🚀.'),
    'HTML and Markdown exports preserve complex download contracts'
);
$duplicate = call('POST', "/api/pages/$pageId/duplicate", [], $csrfHeader, true);
$duplicateId = (int)(json_decode($duplicate['body'], true)['id'] ?? 0);
check($duplicate['status'] === 201 && $duplicateId > 0, 'page duplication creates a new page');
check(call('DELETE', "/api/pages/$duplicateId", null, $csrfHeader)['status'] === 200, 'duplicate moves to trash');
$trash = call('GET', '/api/trash');
check($trash['status'] === 200 && in_array($duplicateId, array_map('intval', array_column(json_decode($trash['body'], true), 'id')), true), 'trash lists deleted top-level items');
check(call('POST', "/api/pages/$duplicateId/restore", [], $csrfHeader, true)['status'] === 200 && call('GET', "/api/pages/$duplicateId")['status'] === 200, 'trashed pages can be restored');
call('DELETE', "/api/pages/$duplicateId", null, $csrfHeader);
call('DELETE', "/api/trash/$duplicateId", null, $csrfHeader);
$conflict = call('PUT', "/api/pages/$pageId", ['content' => '<p>Stale overwrite</p>', 'base_revision' => 1], $csrfHeader, true);
$afterConflict = call('GET', "/api/pages/$pageId");
check($conflict['status'] === 409 && str_contains($afterConflict['body'], 'Protected needle') && !str_contains($afterConflict['body'], 'Stale overwrite'), 'stale autosave cannot overwrite a newer content revision');
$revisions = call('GET', "/api/pages/$pageId/revisions");
$revisionRows = json_decode($revisions['body'], true);
check($revisions['status'] === 200 && array_column($revisionRows, 'revision') === [2, 1], 'successful edits create ordered revision snapshots');
$revisionOne = call('GET', "/api/pages/$pageId/revisions/1");
$revisionTwo = call('GET', "/api/pages/$pageId/revisions/2");
check(str_contains($revisionOne['body'], '<p></p>') && str_contains($revisionTwo['body'], 'Protected needle'), 'revision details preserve earlier and current content');
$restoreOne = call('POST', "/api/pages/$pageId/revisions/1/restore", ['base_revision' => 2], $csrfHeader, true);
$restoredOne = call('GET', "/api/pages/$pageId");
check($restoreOne['status'] === 200 && str_contains($restoredOne['body'], '"content_revision":3') && !str_contains($restoredOne['body'], 'Protected needle'), 'an earlier revision can be restored as a new head version');
$restoreTwo = call('POST', "/api/pages/$pageId/revisions/2/restore", ['base_revision' => 3], $csrfHeader, true);
$restoredTwo = call('GET', "/api/pages/$pageId");
check($restoreTwo['status'] === 200 && str_contains($restoredTwo['body'], '"content_revision":4') && str_contains($restoredTwo['body'], 'Protected needle'), 'restoring the later revision recovers an accidental overwrite');
$restoreConflict = call('POST', "/api/pages/$pageId/revisions/1/restore", ['base_revision' => 2], $csrfHeader, true);
check($restoreConflict['status'] === 409, 'stale revision restore cannot overwrite newer content');
$tree = call('GET', '/api/bootstrap');
$treeData = json_decode($tree['body'], true);
check(count(array_filter($treeData['pages'] ?? [], fn(array $page): bool => $page['title'] === 'Test folder' && $page['kind'] === 'folder')) === 1, 'folder appears in the workspace tree');
$legacyPage = call('GET', "/public/$pageId");
check($legacyPage['status'] === 301 && in_array("Location: /p/$slug", $legacyPage['headers'], true), 'numeric public URL permanently redirects to its slug');
$publicPage = call('GET', "/p/$slug");
check(
    $publicPage['status'] === 200
        && str_contains($publicPage['body'], 'Protected needle')
        && str_contains($publicPage['body'], 'rel="canonical"')
        && str_contains($publicPage['body'], 'property="og:title"')
        && str_contains($publicPage['body'], 'class="public-directory"')
        && str_contains($publicPage['body'], 'External security source')
        && str_contains($publicPage['body'], "/p/$slug")
        && str_contains($publicPage['body'], 'Test folder')
        && str_contains($publicPage['body'], 'Page information')
        && str_contains($publicPage['body'], 'Private author')
        && !str_contains($publicPage['body'], 'href="/u/owner-1"'),
    'public page includes discovery and provenance without exposing its private author profile'
);
$publicSearch = call('GET', '/public?q=Protected%20needle');
check($publicSearch['status'] === 200 && str_contains($publicSearch['body'], 'Authenticated page updated'), 'public search finds published content');
$tagFilter = call('GET', '/public?tag=security');
check($tagFilter['status'] === 200 && str_contains($tagFilter['body'], 'Authenticated page updated'), 'public tag filter finds published pages');
$tagIndex = call('GET', '/tags');
check($tagIndex['status'] === 200 && str_contains($tagIndex['body'], 'security') && str_contains($tagIndex['body'], '1 page'), 'public tag directory counts published pages');

$collaborator = call('POST', '/api/collaboration/users', ['username' => 'reader', 'password' => 'reader secure password'], $csrfHeader, true);
$collaboratorId = (int)(json_decode($collaborator['body'], true)['id'] ?? 0);
check($collaborator['status'] === 201 && $collaboratorId > 0, 'administrator creates a local collaborator account');
$share = call('POST', '/api/shares', ['resource_type' => 'page', 'resource_id' => $pageId, 'user_id' => $collaboratorId, 'role' => 'viewer'], $csrfHeader, true);
$shareId = (int)(json_decode($share['body'], true)['id'] ?? 0);
check($share['status'] === 201 && $shareId > 0, 'page owner grants view access to a collaborator');
$shares = call('GET', "/api/shares?resource_type=page&resource_id=$pageId");
check($shares['status'] === 200 && str_contains($shares['body'], 'reader') && str_contains($shares['body'], 'viewer'), 'page owner lists direct collaborator grants');

$ownerCookie = $cookie;
$cookie = '';
$readerLoginPage = call('GET', '/login');
$readerLogin = call('POST', '/login', [
    'csrf' => csrfFrom($readerLoginPage['body']),
    'username' => 'reader',
    'password' => 'reader secure password',
]);
$readerBootstrap = call('GET', '/api/bootstrap');
$readerData = json_decode($readerBootstrap['body'], true);
$readerCsrf = ['X-CSRF-Token: ' . ($readerData['csrfToken'] ?? '')];
$readerWrite = call('PUT', "/api/pages/$pageId", ['title' => 'Viewer overwrite'], $readerCsrf, true);
check(
    $readerLogin['status'] === 303
        && $readerBootstrap['status'] === 200
        && in_array($pageId, array_map('intval', array_column($readerData['pages'] ?? [], 'id')), true)
        && $readerWrite['status'] === 403,
    'viewer sees the shared page but cannot edit it'
);
$readerCookie = $cookie;
$cookie = $ownerCookie;
$editorShare = call('POST', '/api/shares', ['resource_type' => 'page', 'resource_id' => $pageId, 'user_id' => $collaboratorId, 'role' => 'editor'], $csrfHeader, true);
$cookie = $readerCookie;
$readerChild = call('POST', '/api/pages', ['space_id' => $spaceId, 'parent_id' => $pageId, 'title' => 'Collaborative child'], $readerCsrf, true);
check($editorShare['status'] === 201 && $readerChild['status'] === 201, 'editor access can create content inside the shared page subtree');
$cookie = $ownerCookie;
check(call('DELETE', "/api/shares/$shareId", null, $csrfHeader)['status'] === 200, 'page owner revokes collaborator access');

$private = call('POST', '/api/pages', ['space_id' => $spaceId, 'title' => 'Never public'], $csrfHeader, true);
$privateId = (int)(json_decode($private['body'], true)['id'] ?? 0);
$privateSlug = (string)(json_decode(call('GET', "/api/pages/$privateId")['body'], true)['slug'] ?? '');
call('PUT', "/api/pages/$privateId", ['content' => '<p>Top secret canary phrase</p><p><a href="/page/' . $pageId . '">Stable internal link</a></p>', 'tags' => ['private-canary'], 'base_revision' => 1], $csrfHeader, true);
$privateLink = call('GET', "/page/$privateId");
$privateCanonical = call('GET', "/page/$privateSlug");
$cookie = $readerCookie;
$inaccessiblePrivateSlug = call('GET', "/page/$privateSlug");
$cookie = $ownerCookie;
check(
    $privateLink['status'] === 302
        && in_array("Location: /page/$privateSlug", $privateLink['headers'], true)
        && $privateCanonical['status'] === 200
        && str_contains(implode("\n", $privateCanonical['headers']), 'X-Robots-Tag: noindex, nofollow')
        && $inaccessiblePrivateSlug['status'] === 404,
    'legacy numeric page URLs redirect to authorized, private canonical slug routes'
);
$nestedPublic = call('POST', '/api/pages', ['space_id' => $spaceId, 'parent_id' => $privateId, 'title' => 'Nested public child'], $csrfHeader, true);
$nestedPublicId = (int)(json_decode($nestedPublic['body'], true)['id'] ?? 0);
call('PUT', "/api/pages/$nestedPublicId", ['content' => '<p>Visible child content</p><p><a href="/page/' . $slug . '">Public target</a> <a href="/page/' . $privateSlug . '">Private target</a></p>', 'is_public' => 1, 'base_revision' => 1], $csrfHeader, true);
$nestedPublicPage = call('GET', '/p/' . (json_decode(call('GET', "/api/pages/$nestedPublicId")['body'], true)['slug'] ?? ''));
check(str_contains($nestedPublicPage['body'], "/p/$slug") && !str_contains($nestedPublicPage['body'], "/page/$privateSlug"), 'public pages expose only published slug link targets');
$privateSearch = call('GET', '/public?q=secret%20canary');
$privateTags = call('GET', '/tags');
$privateDirectory = call('GET', '/public');
$sitemap = call('GET', '/sitemap.xml');
$feed = call('GET', '/feed.xml');
check(
    !str_contains($privateSearch['body'], 'Never public')
        && !str_contains($privateTags['body'], 'private-canary')
        && !str_contains($privateDirectory['body'], 'Never public')
        && str_contains($privateDirectory['body'], 'Nested public child')
        && !str_contains($sitemap['body'], 'never-public')
        && !str_contains($feed['body'], 'Top secret canary')
        && str_contains($sitemap['body'], "/p/$slug")
        && str_contains($feed['body'], 'Authenticated page updated'),
    'sitemap, feed, search, and tags include public pages without private leaks'
);
$search = call('GET', '/api/search?q=Protected%20needle');
check($search['status'] === 200 && str_contains($search['body'], 'Authenticated page updated'), 'authenticated search');

$secondSpace = call('POST', '/api/spaces', ['name' => 'Archive'], $csrfHeader, true);
$secondSpaceId = (int)(json_decode($secondSpace['body'], true)['id'] ?? 0);
check($secondSpace['status'] === 201 && $secondSpaceId > 0, 'second space creation');
$spaceUpdate = call('PUT', "/api/spaces/$secondSpaceId", ['name' => 'Archive updated', 'description' => 'Characterized update', 'color' => '#123456'], $csrfHeader, true);
check($spaceUpdate['status'] === 200, 'space settings can be updated');
$staleMove = call('PUT', '/api/tree/reorder', ['source_id' => $folderId, 'space_id' => $secondSpaceId, 'parent_id' => null, 'ordered_ids' => [$folderId, $pageId]], $csrfHeader, true);
check($staleMove['status'] === 409, 'atomic reorder rejects a stale sibling snapshot');
$moveTree = call('PUT', '/api/tree/reorder', ['source_id' => $folderId, 'space_id' => $secondSpaceId, 'parent_id' => null, 'ordered_ids' => [$folderId]], $csrfHeader, true);
$movedTree = json_decode(call('GET', '/api/bootstrap')['body'], true);
$movedIds = array_column(array_filter($movedTree['pages'] ?? [], fn(array $page): bool => (int)$page['space_id'] === $secondSpaceId), 'id');
check($moveTree['status'] === 200 && in_array($folderId, $movedIds, true) && in_array($pageId, $movedIds, true), 'moving a folder between spaces preserves its page subtree');
$movedPublicTree = call('GET', "/p/$slug");
check(str_contains($movedPublicTree['body'], 'Archive updated') && str_contains($movedPublicTree['body'], 'Test folder') && !str_contains($movedPublicTree['body'], 'Never public'), 'public directory follows moved ancestry without exposing private page ancestors');

check(call('DELETE', "/api/pages/$pageId", null, $csrfHeader)['status'] === 200, 'authenticated move to trash');
check(call('DELETE', "/api/trash/$pageId", null, $csrfHeader)['status'] === 200, 'authenticated permanent deletion');
check(call('DELETE', "/api/pages/$folderId", null, $csrfHeader)['status'] === 200, 'folder moves to trash');
check(call('DELETE', "/api/trash/$folderId", null, $csrfHeader)['status'] === 200, 'folder permanent deletion');
check(call('DELETE', "/api/spaces/$secondSpaceId", null, $csrfHeader)['status'] === 200, 'second space cleanup');
check(call('DELETE', "/api/spaces/$spaceId", null, $csrfHeader)['status'] === 409, 'the final space cannot be deleted');
check(call('DELETE', "/api/pages/$privateId", null, $csrfHeader)['status'] === 200, 'private test page moves to trash');
check(call('DELETE', "/api/trash/$privateId", null, $csrfHeader)['status'] === 200, 'private test page permanent deletion');

$setupLocked = call('GET', '/setup');
check($setupLocked['status'] === 303 && in_array('Location: /login', $setupLocked['headers'], true), 'setup locks after first account');

$wrongAccount = call('PUT', '/api/account', ['username' => 'owner', 'current_password' => 'wrong', 'new_password' => ''], $csrfHeader, true);
check($wrongAccount['status'] === 403, 'account changes require the current password');
$invalidate = call('POST', '/api/account/invalidate-sessions', ['current_password' => 'correct horse battery staple'], $csrfHeader, true);
$invalidateData = json_decode($invalidate['body'], true);
check($invalidate['status'] === 200 && strlen((string)($invalidateData['csrfToken'] ?? '')) === 64, 'owner can invalidate other sessions');
$csrfHeader = ['X-CSRF-Token: ' . $invalidateData['csrfToken']];
$account = call('PUT', '/api/account', ['username' => 'owner', 'current_password' => 'correct horse battery staple', 'new_password' => ''], $csrfHeader, true);
$accountData = json_decode($account['body'], true);
check($account['status'] === 200 && ($accountData['username'] ?? '') === 'owner', 'owner credentials can be safely updated');
$csrfHeader = ['X-CSRF-Token: ' . $accountData['csrfToken']];

$logout = call('POST', '/logout', null, $csrfHeader);
check($logout['status'] === 200, 'sign out succeeds');
check(call('GET', '/api/bootstrap')['status'] === 401, 'signed-out session loses access');
$publicHome = call('GET', '/');
check($publicHome['status'] === 200 && !str_contains($publicHome['body'], 'Authenticated page updated'), 'deleted pages never appear on public home');

$loginPage = call('GET', '/login');
$badLogin = call('POST', '/login', [
    'csrf' => csrfFrom($loginPage['body']),
    'username' => 'owner',
    'password' => 'wrong password',
]);
check($badLogin['status'] === 401, 'invalid credentials are rejected');

$login = call('POST', '/login', [
    'csrf' => csrfFrom($badLogin['body']),
    'username' => 'owner',
    'password' => 'correct horse battery staple',
]);
check($login['status'] === 303 && in_array('Location: /dashboard', $login['headers'], true), 'valid credentials restore dashboard access');
$loginWhileAuthenticated = call('GET', '/login');
check($loginWhileAuthenticated['status'] === 303 && in_array('Location: /dashboard', $loginWhileAuthenticated['headers'], true), 'authenticated owners leave the login page for the dashboard');
$missingRoute = call('GET', '/route-that-does-not-exist');
check($missingRoute['status'] === 404 && str_contains($missingRoute['body'], 'Route not found.'), 'authenticated fallback keeps its JSON not-found contract');
$profileBootstrap = json_decode(call('GET', '/api/bootstrap')['body'], true);
$csrfHeader = ['X-CSRF-Token: ' . ($profileBootstrap['csrfToken'] ?? '')];

$profile = call('GET', '/api/profile');
$profileData = json_decode($profile['body'], true);
check(
    $profile['status'] === 200
        && ($profileData['username'] ?? '') === 'owner'
        && ($profileData['profile_slug'] ?? '') === 'owner-1'
        && ($profileData['profile_visibility'] ?? '') === 'private'
        && !array_key_exists('avatar_reference', $profileData),
    'authenticated profile settings expose a safe private self projection'
);
$profileWithoutCsrf = call('PUT', '/api/profile', [
    'username' => 'owner',
    'display_name' => 'Rejected profile update',
    'biography' => '',
    'profile_visibility' => 'public',
], ['Content-Type: application/json'], true);
check(
    $profileWithoutCsrf['status'] === 403
        && (json_decode(call('GET', '/api/profile')['body'], true)['profile_visibility'] ?? '') === 'private',
    'profile mutations require CSRF before changing identity visibility'
);
$profileUpdate = call('PUT', '/api/profile', [
    'username' => 'owner',
    'display_name' => 'Owner Profile',
    'biography' => 'Profile API smoke biography',
    'profile_visibility' => 'members',
], $csrfHeader, true);
$profileUpdateData = json_decode($profileUpdate['body'], true);
check(
    $profileUpdate['status'] === 200
        && ($profileUpdateData['display_name'] ?? '') === 'Owner Profile'
        && ($profileUpdateData['profile_visibility'] ?? '') === 'members'
        && !isset($profileUpdateData['csrfToken']),
    'profile metadata updates without a password or session rotation'
);
$unconfirmedUsername = call('PUT', '/api/profile', [
    'username' => 'profile-owner',
    'display_name' => 'Owner Profile',
    'biography' => 'Profile API smoke biography',
    'profile_visibility' => 'members',
], $csrfHeader, true);
check($unconfirmedUsername['status'] === 403, 'profile username changes require the current password');
$renamedProfile = call('PUT', '/api/profile', [
    'username' => 'profile-owner',
    'display_name' => 'Owner Profile',
    'biography' => 'Profile API smoke biography',
    'profile_visibility' => 'members',
    'current_password' => 'correct horse battery staple',
], $csrfHeader, true);
$renamedProfileData = json_decode($renamedProfile['body'], true);
check(
    $renamedProfile['status'] === 200
        && ($renamedProfileData['username'] ?? '') === 'profile-owner'
        && ($renamedProfileData['profile_slug'] ?? '') === 'owner-1'
        && strlen((string)($renamedProfileData['csrfToken'] ?? '')) === 64
        && (json_decode(call('GET', '/api/bootstrap')['body'], true)['username'] ?? '') === 'profile-owner',
    'password-confirmed username changes rotate the session and preserve the profile slug'
);
$csrfHeader = ['X-CSRF-Token: ' . $renamedProfileData['csrfToken']];
$avatarBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
if (!is_string($avatarBytes)) throw new RuntimeException('Could not decode avatar smoke fixture.');
$avatarWithoutCsrf = callMultipart('/api/profile/avatar', 'avatar', 'rejected.png', 'image/png', $avatarBytes, []);
check(
    $avatarWithoutCsrf['status'] === 403
        && (json_decode(call('GET', '/api/profile')['body'], true)['has_avatar'] ?? true) === false,
    'avatar uploads require CSRF before writing profile media'
);
$avatarUpload = callMultipart('/api/profile/avatar', 'avatar', '../../ignored.php.png', 'application/x-php', $avatarBytes, $csrfHeader);
$avatarUploadData = json_decode($avatarUpload['body'], true);
check(
    $avatarUpload['status'] === 201
        && ($avatarUploadData['mime'] ?? '') === 'image/png'
        && ($avatarUploadData['avatar_url'] ?? '') === '/avatar/owner-1',
    'profile avatar upload trusts inspected image content and returns only its authorized route'
);
$ownerAvatar = call('GET', '/avatar/owner-1');
check(
    $ownerAvatar['status'] === 200
        && in_array('Content-Type: image/png', $ownerAvatar['headers'], true)
        && in_array('Cache-Control: no-store', $ownerAvatar['headers'], true),
    'profile owners can retrieve avatars through a non-cacheable authorization route'
);
$profilePublicPage = call('POST', '/api/pages', ['space_id' => $spaceId, 'title' => 'Profile public listing'], $csrfHeader, true);
$profilePublicPageId = (int)(json_decode($profilePublicPage['body'], true)['id'] ?? 0);
$profilePublicPageRow = json_decode(call('GET', "/api/pages/$profilePublicPageId")['body'], true);
$profilePublicSlug = (string)($profilePublicPageRow['slug'] ?? '');
call('PUT', "/api/pages/$profilePublicPageId", ['content' => '<p>Public profile listing content</p>', 'is_public' => 1, 'base_revision' => 1], $csrfHeader, true);
$profilePrivatePage = call('POST', '/api/pages', ['space_id' => $spaceId, 'title' => 'Profile private collaboration'], $csrfHeader, true);
$profilePrivatePageId = (int)(json_decode($profilePrivatePage['body'], true)['id'] ?? 0);
$profilePrivateSlug = (string)(json_decode(call('GET', "/api/pages/$profilePrivatePageId")['body'], true)['slug'] ?? '');
$profilePrivateShare = call('POST', '/api/shares', ['resource_type' => 'page', 'resource_id' => $profilePrivatePageId, 'user_id' => $collaboratorId, 'role' => 'viewer'], $csrfHeader, true);
check($profilePublicPageId > 0 && $profilePrivatePageId > 0 && $profilePrivateShare['status'] === 201, 'profile route fixtures include public and privately shared authored pages');

$selfProfilePage = call('GET', '/u/owner-1');
check(
    $selfProfilePage['status'] === 200
        && in_array('Cache-Control: no-store', $selfProfilePage['headers'], true)
        && in_array('X-Robots-Tag: noindex, nofollow', $selfProfilePage['headers'], true)
        && str_contains($selfProfilePage['body'], 'Owned pages')
        && str_contains($selfProfilePage['body'], 'Published by me')
        && str_contains($selfProfilePage['body'], "/page/$profilePublicSlug")
        && str_contains($selfProfilePage['body'], "/page/$profilePrivateSlug"),
    'self profile pages use private caching and separate owned, shared, and published lenses'
);
$profileOwnerCookie = $cookie;
$cookie = $readerCookie;
$memberProfilePage = call('GET', '/u/owner-1');
$memberPageInformation = json_decode(call('GET', "/api/pages/$profilePrivatePageId")['body'], true)['page_information'] ?? [];
check(
    $memberProfilePage['status'] === 200
        && str_contains($memberProfilePage['body'], 'Pages you can view')
        && str_contains($memberProfilePage['body'], "/p/$profilePublicSlug")
        && str_contains($memberProfilePage['body'], "/page/$profilePrivateSlug")
        && in_array('X-Robots-Tag: noindex, nofollow', $memberProfilePage['headers'], true)
        && ($memberPageInformation['author']['name'] ?? '') === 'Owner Profile'
        && ($memberPageInformation['author']['profile_url'] ?? '') === '/u/owner-1',
    'signed-in profile and page information expose only authorized member identity and page links'
);
$cookie = '';
$membersAnonymous = call('GET', '/u/owner-1');
$missingProfile = call('GET', '/u/missing-profile');
$malformedProfile = call('GET', '/u/INVALID');
check(
    $membersAnonymous['status'] === 404
        && $membersAnonymous['body'] === $missingProfile['body']
        && $missingProfile['body'] === $malformedProfile['body']
        && !str_contains($membersAnonymous['body'], 'Profile public listing'),
    'members-only, missing, and malformed profile routes are indistinguishable anonymously'
);
$cookie = $profileOwnerCookie;
$publicProfile = call('PUT', '/api/profile', [
    'username' => 'profile-owner',
    'display_name' => 'Owner Profile',
    'biography' => 'Profile API smoke biography',
    'profile_visibility' => 'public',
], $csrfHeader, true);
check($publicProfile['status'] === 200, 'profile visibility can be explicitly changed to public');
$authenticatedCookie = $cookie;
$cookie = '';
$publicAvatar = call('GET', '/avatar/owner-1');
$anonymousProfilePage = call('GET', '/u/owner-1');
$anonymousAuthoredPage = call('GET', "/p/$profilePublicSlug");
check(
    $publicAvatar['status'] === 200
        && $anonymousProfilePage['status'] === 200
        && in_array('Cache-Control: no-cache', $anonymousProfilePage['headers'], true)
        && !str_contains(implode("\n", $anonymousProfilePage['headers']), 'X-Robots-Tag')
        && str_contains($anonymousProfilePage['body'], 'rel="canonical"')
        && str_contains($anonymousProfilePage['body'], 'Profile public listing')
        && str_contains($anonymousProfilePage['body'], "/p/$profilePublicSlug")
        && !str_contains($anonymousProfilePage['body'], 'Profile private collaboration')
        && $anonymousAuthoredPage['status'] === 200
        && str_contains($anonymousAuthoredPage['body'], 'href="/u/owner-1"')
        && str_contains($anonymousAuthoredPage['body'], 'src="/avatar/owner-1"')
        && str_contains($anonymousAuthoredPage['body'], 'First published'),
    'public profiles and page information expose only explicitly public identity and authorship metadata anonymously'
);
$cookie = $authenticatedCookie;
$privateProfile = call('PUT', '/api/profile', [
    'username' => 'profile-owner',
    'display_name' => 'Owner Profile',
    'biography' => 'Profile API smoke biography',
    'profile_visibility' => 'private',
], $csrfHeader, true);
check($privateProfile['status'] === 200, 'profile visibility can return to private without changing the profile slug');
$cookie = $readerCookie;
$hiddenMemberProfile = call('GET', '/u/owner-1');
$hiddenAuthorPage = call('GET', "/api/pages/$profilePrivatePageId");
$hiddenAuthorInformation = json_decode($hiddenAuthorPage['body'], true)['page_information']['author'] ?? [];
$cookie = $authenticatedCookie;
$privateSelfProfile = call('GET', '/u/owner-1');
check(
    $hiddenMemberProfile['status'] === 404
        && $privateSelfProfile['status'] === 200
        && $hiddenAuthorPage['status'] === 200
        && $hiddenAuthorInformation === ['state' => 'private', 'name' => 'Private author', 'profile_url' => null, 'avatar_url' => null]
        && !str_contains($hiddenAuthorPage['body'], 'Owner Profile')
        && !str_contains($hiddenAuthorPage['body'], 'owner-1'),
    'private profiles and author metadata remain hidden from otherwise authorized page viewers'
);
$cookie = '';
$hiddenAvatar = call('GET', '/avatar/owner-1');
check($hiddenAvatar['status'] === 404, 'private profile avatars are indistinguishable from missing avatars anonymously');
$cookie = $authenticatedCookie;
$removedAvatar = call('DELETE', '/api/profile/avatar', null, $csrfHeader);
check($removedAvatar['status'] === 200 && call('GET', '/avatar/owner-1')['status'] === 404, 'avatar removal clears both self-service state and delivery');

echo "\nn3 authentication smoke test passed.\n";
