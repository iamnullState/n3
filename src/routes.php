<?php
declare(strict_types=1);

use N3\Config;
use N3\Controller\AccountController;
use N3\Controller\AppSettingsController;
use N3\Controller\AuthController;
use N3\Controller\PluginAdminController;
use N3\Controller\ProfileController;
use N3\Controller\ProfilePageController;
use N3\Controller\PublicController;
use N3\Controller\SyndicationController;
use N3\Controller\SystemDiagnosticsController;
use N3\Http\Request;
use N3\Http\Response;
use N3\Http\Router;
use N3\Http\JsonBody;
use N3\Repository\PublicPageRepository;
use N3\Repository\AppSettingsRepository;
use N3\Repository\PageRepository;
use N3\Repository\PageReferenceRepository;
use N3\Repository\PluginEnablementRepository;
use N3\Repository\ProfileRepository;
use N3\Repository\RevisionRepository;
use N3\Repository\SpaceRepository;
use N3\Repository\TagRepository;
use N3\Repository\TrashRepository;
use N3\Service\AccountService;
use N3\Service\AppSettingsService;
use N3\Service\AccessService;
use N3\Service\AuthService;
use N3\Service\HtmlSanitizer;
use N3\Service\MarkdownExportService;
use N3\Service\MediaService;
use N3\Service\DomainException;
use N3\Service\FeatureImageService;
use N3\Service\PageTreeService;
use N3\Service\PageInformationService;
use N3\Service\PageProjectionService;
use N3\Service\PluginContributionService;
use N3\Service\RevisionRestoreService;
use N3\Service\SystemDiagnosticsService;
use N3\Service\PublishingService;
use N3\Service\PluginEnablementService;
use N3\Service\PluginArchiveInstaller;
use N3\Service\ProfileAvatarService;
use N3\Service\ProfileSettingsService;
use N3\Service\ProfileService;
use N3\Support\Version;
use N3\View\ViewRenderer;

function sendSecurityHeaders(): void
{
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'; img-src 'self' http: https: data:; media-src 'self' http: https:; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'");
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header('X-Request-ID: ' . requestId());
    if (Config::publicHttps()) header('Strict-Transport-Security: max-age=31536000');
}

sendSecurityHeaders();

function jsonResponse(mixed $data, int $status = 200): never
{
    Response::json($data, $status)->send();
}

function csrfToken(): string
{
    startSecureSession();
    return (string)$_SESSION['csrf'];
}

function verifyCsrf(): void
{
    startSecureSession();
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '';
    if (!is_string($provided) || !hash_equals((string)$_SESSION['csrf'], $provided)) {
        if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/api/')) {
            jsonResponse(['error' => 'Your session expired. Refresh the page and try again.'], 403);
        }
        renderAuthPage('login', 'Your session expired. Please try again.', 403);
    }
}

function currentUser(): ?array
{
    startSecureSession();
    if (empty($_SESSION['user_id'])) return null;
    $user = (new AuthService(db()))->findSessionUser((int)$_SESSION['user_id']);
    if (!$user || (int)($_SESSION['session_version'] ?? 0) !== (int)$user['session_version']) {
        $_SESSION = [];
        return null;
    }
    return $user;
}

function renderAuthPage(string $mode, string $error = '', int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $setup = $mode === 'setup';
    $views = new ViewRenderer(Config::projectRoot() . '/views');
    $applicationSettings = (new AppSettingsService(new AppSettingsRepository(db()), Config::dataDir()))->all();
    echo $views->render('auth/form', [
        'appName' => Config::appName(),
        'button' => $setup ? 'Create account' : 'Sign in',
        'copy' => $setup ? 'Choose the credentials for your private wiki.' : 'Sign in to your private wiki.',
        'error' => $error,
        'mode' => $mode,
        'passwordAutocomplete' => $setup ? 'new-password' : 'current-password',
        'passwordMinlength' => $setup ? 12 : 1,
        'setup' => $setup,
        'title' => $setup ? 'Create your account' : 'Welcome back',
        'token' => csrfToken(),
        'applicationSettings' => $applicationSettings,
    ]);
    exit;
}

function cleanText(mixed $value, int $max = 160): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

function slugify(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-') ?: 'page';
}

function sendMediaFile(array $media, bool $private = false): never
{
    $size = (int)$media['size'];
    $start = 0;
    $end = $size - 1;
    $status = 200;
    $range = trim((string)($_SERVER['HTTP_RANGE'] ?? ''));
    if ($range !== '') {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $match)) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        if ($match[1] === '' && $match[2] !== '') {
            $length = min((int)$match[2], $size);
            $start = $size - $length;
        } else {
            $start = (int)$match[1];
            if ($match[2] !== '') $end = min((int)$match[2], $end);
        }
        if ($start > $end || $start >= $size) {
            header('Content-Range: bytes */' . $size);
            http_response_code(416);
            exit;
        }
        $status = 206;
    }
    http_response_code($status);
    header('Content-Type: ' . $media['mime']);
    header('Content-Length: ' . ($end - $start + 1));
    header('Accept-Ranges: bytes');
    header('Cache-Control: ' . ($private ? 'private, no-store' : 'public, max-age=31536000, immutable'));
    if ($status === 206) header("Content-Range: bytes $start-$end/$size");
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
    $handle = fopen($media['path'], 'rb');
    if ($handle === false) { http_response_code(500); exit; }
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = fread($handle, min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') break;
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($handle);
    exit;
}

function sendAvatarFile(array $avatar): never
{
    http_response_code(200);
    header('Content-Type: ' . $avatar['mime']);
    header('Content-Length: ' . (int)$avatar['size']);
    header('Cache-Control: no-store');
    header('Content-Disposition: inline');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($avatar['path']);
    exit;
}

function pageRow(int $id, bool $includeDeleted = false, bool $edit = false, bool $manage = false): array
{
    $row = (new PageRepository(db()))->find($id, $includeDeleted);
    $user = currentUser();
    if (!$user) jsonResponse(['error' => 'Authentication required.'], 401);
    $access = new AccessService(db(), (int)$user['id']);
    if (!$row || !$access->canViewPage($id)) jsonResponse(['error' => 'Page not found.'], 404);
    if ($manage && !$access->canManagePage($id)) jsonResponse(['error' => 'Only the space owner can manage sharing for this page.'], 403);
    if ($edit && !$access->canEditPage($id)) jsonResponse(['error' => 'You have view-only access to this page.'], 403);
    return $row;
}

function replacePageTags(int $pageId, mixed $values, ?int $actorId = null): void
{
    if (!is_array($values)) jsonResponse(['error' => 'Tags must be an array.'], 422);
    $tags = [];
    foreach ($values as $value) {
        $name = mb_strtolower(cleanText($value, 40));
        if ($name !== '' && preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _-]*$/u', $name)) $tags[$name] = true;
        if (count($tags) >= 20) break;
    }
    (new TagRepository(db()))->replaceForPage($pageId, array_keys($tags), $actorId);
}

function cleanReferences(mixed $values): array
{
    if (!is_array($values)) jsonResponse(['error' => 'References must be an array.'], 422);
    $references = [];
    foreach (array_slice($values, 0, 40) as $value) {
        if (!is_array($value)) continue;
        $label = cleanText($value['label'] ?? '', 160);
        $url = trim((string)($value['url'] ?? ''));
        if ($label === '' || !preg_match('~^(?:https?://[^\s]+|/page/[a-z0-9-]+)$~iD', $url)) continue;
        $references[] = ['label' => $label, 'url' => mb_substr($url, 0, 2000)];
    }
    return $references;
}

$router = new Router($request);
$method = $request->method();
$path = $request->path();

if ($router->matches('ANY', '/api/health') !== null) jsonResponse(['status' => 'ok', 'version' => Version::current()]);

if (($method === 'GET' || $method === 'HEAD') && ($route = $router->matches('ANY', '/media/{filename}')) !== null) {
    $media = (new MediaService(Config::dataDir()))->find((string)$route['filename']);
    if ($media === null) { http_response_code(404); exit; }
    sendMediaFile($media);
}

if (pluginManager()->claimsPublicPath($path)) {
    $publicPluginEnablement = new PluginEnablementService(new PluginEnablementRepository(db()));
    pluginManager()->applyEnablementOverrides($publicPluginEnablement->overrides());
    $publicPluginResponse = pluginManager()->publicResponse($request, db());
    if ($publicPluginResponse !== null) $publicPluginResponse->send();
}

startSecureSession();

$appSettingsService = new AppSettingsService(new AppSettingsRepository(db()), Config::dataDir());
if ($path === '/brand/settings' && $method === 'GET') {
    $settings = $appSettingsService->all();
    jsonResponse(['brandName' => $settings['brandName'], 'iconUrl' => $settings['iconUrl'], 'bannerUrl' => $settings['bannerUrl'], 'themes' => $settings['themes']]);
}
if (($method === 'GET' || $method === 'HEAD') && ($route = $router->matches('ANY', '/brand/{kind}')) !== null) {
    $asset = $appSettingsService->brandAsset((string)$route['kind']);
    if ($asset === null) { http_response_code(404); exit; }
    header('Content-Type: ' . $asset['mime']);
    header('Content-Length: ' . $asset['size']);
    header('Cache-Control: public, max-age=300');
    if ($method === 'GET') readfile($asset['path']);
    exit;
}

if (($method === 'GET' || $method === 'HEAD') && ($route = $router->matches('ANY', '/avatar/{slug}')) !== null) {
    $viewer = currentUser();
    $avatar = (new ProfileAvatarService(new ProfileRepository(db()), Config::dataDir()))
        ->findForProfile((string)$route['slug'], $viewer === null ? null : (int)$viewer['id']);
    if ($avatar === null) { http_response_code(404); exit; }
    sendAvatarFile($avatar);
}

$sanitizer = new HtmlSanitizer();
$publishing = new PublishingService(new PublicPageRepository(db()), $sanitizer);
$treeService = new PageTreeService(new SpaceRepository(db()), new PageRepository(db()));
$revisionRestore = new RevisionRestoreService(new PageRepository(db()), new RevisionRepository(db()));
$pageInformation = new PageInformationService(new ProfileRepository(db()));
$pluginContributions = new PluginContributionService(pluginRegistry());
$featureImages = new FeatureImageService();
$pageProjection = new PageProjectionService($pageInformation, $pluginContributions, $featureImages);
$publicController = new PublicController(new PublicPageRepository(db()), new ViewRenderer(Config::projectRoot() . '/views'), $publishing, $pageProjection);
$profileRoute = $router->matches('GET', '/u/{slug}');
if ($profileRoute !== null || $path === '/u' || str_starts_with($path, '/u/')) {
    $viewer = currentUser();
    $viewerId = $viewer === null ? null : (int)$viewer['id'];
    if ($viewer !== null) {
        $profilePluginEnablement = new PluginEnablementService(new PluginEnablementRepository(db()));
        pluginManager()->applyEnablementOverrides($profilePluginEnablement->overrides());
        pluginManager()->boot(db());
    }
    $profilePages = new ProfilePageController(
        new ProfileService(
            new ProfileRepository(db()),
            $viewerId === null ? null : new AccessService(db(), $viewerId),
            $viewerId,
            $pluginContributions,
        ),
        new ViewRenderer(Config::projectRoot() . '/views'),
    );
    if ($profileRoute === null) $profilePages->notFound();
    $profilePages->show((string)$profileRoute['slug']);
}
if (($route = $router->matches('GET', '/public/{id}')) !== null) $publicController->legacyRedirect($route['id']);
if (($route = $router->matches('GET', '/p/{slug}')) !== null) $publicController->page($route['slug']);
if ($method === 'GET' && in_array($path, ['/sitemap.xml', '/feed.xml'], true)) {
    $syndication = new SyndicationController(new PublicPageRepository(db()));
    $path === '/sitemap.xml' ? $syndication->sitemap() : $syndication->feed();
}
if ($method === 'GET' && $path === '/public') $publicController->home(cleanText($_GET['q'] ?? '', 100), mb_strtolower(cleanText($_GET['tag'] ?? '', 40)));
if ($method === 'GET' && $path === '/tags') $publicController->tags();
if ($method === 'GET' && $path === '/' && !currentUser()) $publicController->home(cleanText($_GET['q'] ?? '', 100), mb_strtolower(cleanText($_GET['tag'] ?? '', 40)));

$authService = new AuthService(db());
$authController = new AuthController($authService, $appSettingsService);
if ($path === '/setup') $authController->setup($request);
if ($path === '/login') $authController->login($request);
if ($path === '/logout' && $method === 'POST') $authController->logout();

if (!currentUser()) {
    if (str_starts_with($path, '/api/')) jsonResponse(['error' => 'Authentication required.'], 401);
    Response::redirect($authService->accountExists() ? '/login' : '/setup')->send();
}

$user = currentUser();
$access = new AccessService(db(), (int)$user['id']);

if (str_starts_with($path, '/api/') && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) verifyCsrf();

$profileRepository = new ProfileRepository(db());
$profileController = new ProfileController(
    new ProfileSettingsService($profileRepository, new AccountService(db())),
    new ProfileAvatarService($profileRepository, Config::dataDir()),
);
if ($path === '/api/profile' && $method === 'GET') $profileController->show($user);
if ($path === '/api/profile' && $method === 'PUT') $profileController->update($request, $user);
if ($path === '/api/profile/avatar' && $method === 'POST') $profileController->storeAvatar($user, $_FILES['avatar'] ?? []);
if ($path === '/api/profile/avatar' && $method === 'DELETE') $profileController->removeAvatar($user);

$systemDiagnostics = new SystemDiagnosticsController(new SystemDiagnosticsService(db(), Config::dataDir(), Config::backupDir(), Version::current()));
if ($path === '/api/diagnostics' && $method === 'GET') $systemDiagnostics->index($user);

$appSettings = new AppSettingsController($appSettingsService);
if ($path === '/api/settings' && $method === 'GET') $appSettings->show($user);
if ($path === '/api/settings' && $method === 'PUT') $appSettings->update($request, $user);
if (($route = $router->matches('POST', '/api/settings/brand/{kind}')) !== null) {
    $appSettings->upload((string)$route['kind'], $_FILES['image'] ?? [], $user);
}

$pluginEnablement = new PluginEnablementService(new PluginEnablementRepository(db()));
pluginManager()->applyEnablementOverrides($pluginEnablement->overrides());
$pluginAdmin = new PluginAdminController(pluginManager(), $pluginEnablement, new PluginArchiveInstaller(Config::pluginDir()), db());
if ($path === '/api/plugins' && $method === 'GET') $pluginAdmin->index($user);
if ($path === '/api/plugins/upload' && $method === 'POST') $pluginAdmin->upload($_FILES['plugin'] ?? [], $user);
if (($route = $router->matches('PUT', '/api/plugins/{plugin}')) !== null) {
    $pluginAdmin->update((string)$route['plugin'], $request, $user);
}

pluginManager()->boot(db());

if (($method === 'GET' || $method === 'HEAD') && ($route = $router->matches('ANY', '/plugin-media/{plugin}/{filename}')) !== null) {
    $media = pluginManager()->media((string)$route['plugin'], (string)$route['filename']);
    if ($media === null) { http_response_code(404); exit; }
    sendMediaFile($media, true);
}

if ($path === '/' && $method === 'GET') Response::redirect('/dashboard')->send();

if (($path === '/dashboard' || $path === '/index.php' || $path === '/index.html') && $method === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    readfile(Config::projectRoot() . '/public/index.html');
    exit;
}

if (($route = $router->matches('GET', '/page/{id}')) !== null) {
    $page = pageRow($route['id']);
    if ($page['kind'] !== 'page') Response::redirect('/dashboard')->send();
    header('Location: /page/' . rawurlencode((string)$page['slug']), true, 302);
    exit;
}

if (($route = $router->matches('GET', '/page/{slug}')) !== null) {
    $page = (new PageRepository(db()))->findBySlug((string)$route['slug']);
    if (!$page || !$access->canViewPage((int)$page['id'])) jsonResponse(['error' => 'Page not found.'], 404);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    readfile(Config::projectRoot() . '/public/index.html');
    exit;
}

if (($route = $router->matches('GET', '/preview/{id}')) !== null) {
    $page = pageRow($route['id']);
    if ($page['kind'] !== 'page') Response::redirect('/dashboard')->send();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    $views = new ViewRenderer(Config::projectRoot() . '/views');
    $previewInformation = $pageInformation->forPage($page, (int)$user['id']);
    $previewInformation['plugin_rows'] = $pluginContributions->pageInformationRows($page, $previewInformation, [
        'can_edit' => $access->canEditPage((int)$page['id']),
        'can_manage' => $access->canManagePage((int)$page['id']),
    ]);
    echo $views->render('page/preview', [
        'content' => $sanitizer->clean($page['content']),
        'featureImage' => $featureImages->fromPage($page),
        'page' => $page,
        'pageInformation' => $previewInformation,
        'references' => (new PageReferenceRepository(db()))->forPage((int)$page['id']),
        'related' => (new TagRepository(db()))->relatedForPage((int)$page['id'], $access->accessiblePageIds()),
        'visibility' => (int)$page['is_public'] === 1 ? 'Public' : 'Private preview',
    ]);
    exit;
}

if ($path === '/api/bootstrap' && $method === 'GET') {
    $spaces = (new SpaceRepository(db()))->all($access->accessibleSpaceIds());
    foreach ($spaces as &$space) {
        $space['can_edit'] = $access->canEditSpace((int)$space['id']);
        $space['can_manage'] = $access->canManageSpace((int)$space['id']);
    }
    $pages = (new PageRepository(db()))->active($access->accessiblePageIds());
    foreach ($pages as &$page) {
        $page['tags'] = (new TagRepository(db()))->forPage((int)$page['id']);
        $page['can_edit'] = $access->canEditPage((int)$page['id']);
        $page['can_manage'] = $access->canManagePage((int)$page['id']);
    }
    jsonResponse([
        'appName' => Config::appName(),
        'settings' => $appSettingsService->all(),
        'spaces' => $spaces,
        'pages' => $pages,
        'username' => $user['username'],
        'isAdmin' => (bool)$user['is_admin'],
        'users' => $access->users(),
        'sharedWithMe' => $access->sharedWithMe(),
        'plugins' => pluginRegistry()->plugins(),
        'csrfToken' => csrfToken(),
    ]);
}

if (($route = $router->matches('GET', '/plugin-assets/{plugin}/{filename}')) !== null) {
    $asset = pluginManager()->asset((string)$route['plugin'], (string)$route['filename']);
    if ($asset === null) { http_response_code(404); exit; }
    header('Content-Type: ' . $asset['mime']);
    header('Cache-Control: private, no-cache, max-age=0');
    readfile($asset['path']);
    exit;
}

$accountController = new AccountController(new AccountService(db()));
if ($path === '/api/account' && $method === 'PUT') $accountController->update($request);
if ($path === '/api/account/invalidate-sessions' && $method === 'POST') $accountController->invalidateSessions($request);

if ($path === '/api/collaboration/users' && $method === 'POST') {
    if (!(bool)$user['is_admin']) jsonResponse(['error' => 'Only an administrator can create local collaborator accounts.'], 403);
    $data = JsonBody::decode($request);
    $username = cleanText($data['username'] ?? '', 80);
    $password = (string)($data['password'] ?? '');
    if ($username === '') jsonResponse(['error' => 'A username is required.'], 422);
    if (mb_strlen($password) < 12) jsonResponse(['error' => 'Use at least 12 characters for the collaborator password.'], 422);
    try {
        $id = (new AuthService(db()))->createCollaborator($username, $password);
        jsonResponse(['id' => $id, 'username' => $username], 201);
    } catch (\PDOException $error) {
        if ($error->getCode() === '23000') jsonResponse(['error' => 'That username is unavailable.'], 422);
        throw $error;
    }
}

if ($path === '/api/shares' && $method === 'GET') {
    $resourceType = ($_GET['resource_type'] ?? '') === 'space' ? 'space' : 'page';
    $resourceId = (int)($_GET['resource_id'] ?? 0);
    $managed = $resourceType === 'space' ? $access->canManageSpace($resourceId) : $access->canManagePage($resourceId);
    if (!$managed) jsonResponse(['error' => 'Only the resource owner can view its collaborators.'], 403);
    jsonResponse($access->shares($resourceType, $resourceId));
}

if ($path === '/api/shares' && $method === 'POST') {
    $data = JsonBody::decode($request);
    $resourceType = ($data['resource_type'] ?? '') === 'space' ? 'space' : 'page';
    $resourceId = (int)($data['resource_id'] ?? 0);
    $targetUserId = (int)($data['user_id'] ?? 0);
    $role = ($data['role'] ?? '') === 'editor' ? 'editor' : 'viewer';
    $managed = $resourceType === 'space' ? $access->canManageSpace($resourceId) : $access->canManagePage($resourceId);
    if (!$managed) jsonResponse(['error' => 'Only the resource owner can add collaborators.'], 403);
    if ($targetUserId === (int)$user['id'] || !in_array($targetUserId, array_map('intval', array_column($access->users(), 'id')), true)) {
        jsonResponse(['error' => 'Choose an available collaborator.'], 422);
    }
    $shareId = $access->grant($resourceType, $resourceId, $targetUserId, $role);
    jsonResponse(['id' => $shareId], 201);
}

if (($route = $router->matches('DELETE', '/api/shares/{id}')) !== null) {
    if (!$access->revoke($route['id'])) jsonResponse(['error' => 'Share not found or not owned by you.'], 404);
    jsonResponse(['ok' => true]);
}

if ($path === '/api/media' && $method === 'POST') {
    try {
        jsonResponse((new MediaService(Config::dataDir()))->store($_FILES['media'] ?? []), 201);
    } catch (DomainException $error) {
        jsonResponse(['error' => $error->getMessage()], 422);
    }
}

if ($path === '/api/spaces' && $method === 'POST') {
    $data = JsonBody::decode($request);
    $name = cleanText($data['name'] ?? '');
    if ($name === '') jsonResponse(['error' => 'Space name is required.'], 422);
    $id = (new SpaceRepository(db()))->create($name, cleanText($data['description'] ?? '', 500), cleanText($data['icon'] ?? 'book', 24), cleanText($data['color'] ?? '#415a77', 16), (int)$user['id']);
    jsonResponse(['id' => $id], 201);
}

if (($route = $router->matches('ANY', '/api/spaces/{id}')) !== null) {
    $id = $route['id'];
    if ($method === 'PUT') {
        if (!$access->canManageSpace($id)) jsonResponse(['error' => 'Only the space owner can change these settings.'], 403);
        $data = JsonBody::decode($request);
        $name = cleanText($data['name'] ?? '');
        if ($name === '') jsonResponse(['error' => 'Space name is required.'], 422);
        (new SpaceRepository(db()))->update($id, $name, cleanText($data['description'] ?? '', 500), cleanText($data['color'] ?? '#415a77', 16));
        jsonResponse(['ok' => true]);
    }
    if ($method === 'DELETE') {
        if (!$access->canManageSpace($id)) jsonResponse(['error' => 'Only the space owner can delete this space.'], 403);
        if ((new SpaceRepository(db()))->count() <= 1) jsonResponse(['error' => 'Keep at least one space.'], 409);
        (new SpaceRepository(db()))->delete($id);
        jsonResponse(['ok' => true]);
    }
}

if ($path === '/api/pages' && $method === 'POST') {
    $data = JsonBody::decode($request);
    $spaceId = (int)($data['space_id'] ?? 0);
    $parentId = isset($data['parent_id']) && $data['parent_id'] !== null ? (int)$data['parent_id'] : null;
    $kind = ($data['kind'] ?? 'page') === 'folder' ? 'folder' : 'page';
    $canPlace = $parentId === null ? $access->canEditSpace($spaceId) : $access->canEditPage($parentId);
    if (!$canPlace) jsonResponse(['error' => 'You do not have edit access at that location.'], 403);
    try {
        $treeService->validatePlacement($spaceId, $parentId);
    } catch (DomainException $error) {
        jsonResponse(['error' => $error->getMessage()], $error->status());
    }
    $defaultTitle = $kind === 'folder' ? 'New folder' : 'Untitled';
    $title = cleanText($data['title'] ?? $defaultTitle) ?: $defaultTitle;
    $id = (new PageRepository(db()))->create($spaceId, $parentId, $title, $kind, slugify(cleanText($data['title'] ?? $defaultTitle)), (int)$user['id']);
    jsonResponse(['id' => $id], 201);
}

if ($path === '/api/tree/reorder' && $method === 'PUT') {
    $data = JsonBody::decode($request);
    $sourceId = (int)($data['source_id'] ?? 0);
    $spaceId = (int)($data['space_id'] ?? 0);
    $parentId = array_key_exists('parent_id', $data) && $data['parent_id'] !== null ? (int)$data['parent_id'] : null;
    $orderedIds = array_map('intval', is_array($data['ordered_ids'] ?? null) ? $data['ordered_ids'] : []);
    $canPlace = $parentId === null ? $access->canEditSpace($spaceId) : $access->canEditPage($parentId);
    if (!$access->canEditPage($sourceId) || !$canPlace) {
        jsonResponse(['error' => 'You do not have permission to move that item there.'], 403);
    }
    try {
        $treeService->reorder($sourceId, $spaceId, $parentId, $orderedIds, (int)$user['id']);
    } catch (DomainException $error) {
        jsonResponse(['error' => $error->getMessage()], $error->status());
    }
    jsonResponse(['ok' => true]);
}

if (($route = $router->matches('GET', '/api/pages/{id}/revisions')) !== null) {
    $page = pageRow($route['id']);
    if ($page['kind'] !== 'page') jsonResponse(['error' => 'Folders do not have revision history.'], 422);
    jsonResponse((new RevisionRepository(db()))->forPage($route['id']));
}

if (($route = $router->matches('GET', '/api/pages/{id}/revisions/{revision}')) !== null) {
    pageRow($route['id']);
    $revision = (new RevisionRepository(db()))->find($route['id'], $route['revision']);
    if (!$revision) jsonResponse(['error' => 'Revision not found.'], 404);
    jsonResponse($revision);
}

if (($route = $router->matches('POST', '/api/pages/{id}/revisions/{revision}/restore')) !== null) {
    pageRow($route['id'], false, true);
    $data = JsonBody::decode($request);
    try {
        jsonResponse($revisionRestore->restore($route['id'], $route['revision'], (int)($data['base_revision'] ?? 0), (int)$user['id']));
    } catch (DomainException $error) {
        jsonResponse(['error' => $error->getMessage()], $error->status());
    }
}

if (($route = $router->matches('ANY', '/api/pages/{id}')) !== null) {
    $id = $route['id'];
    if ($method === 'GET') {
        $page = pageRow($id);
        jsonResponse($pageProjection->authenticatedDetail($page, (int)$user['id'], [
            'tags' => (new TagRepository(db()))->forPage($id),
            'references' => (new PageReferenceRepository(db()))->forPage($id),
            'related' => (new TagRepository(db()))->relatedForPage($id, $access->accessiblePageIds()),
            'can_edit' => $access->canEditPage($id),
            'can_manage' => $access->canManagePage($id),
        ]));
    }
    if ($method === 'PUT') {
        $page = pageRow($id, false, true);
        $data = JsonBody::decode($request);
        $contentWrite = array_key_exists('content', $data);
        $baseRevision = isset($data['base_revision']) ? (int)$data['base_revision'] : 0;
        if ($contentWrite && $baseRevision < 1) jsonResponse(['error' => 'A base revision is required to save content.'], 428);
        $spaceId = array_key_exists('space_id', $data) ? (int)$data['space_id'] : (int)$page['space_id'];
        $parentId = array_key_exists('parent_id', $data) ? ($data['parent_id'] === null ? null : (int)$data['parent_id']) : ($page['parent_id'] === null ? null : (int)$page['parent_id']);
        $placementChanged = $spaceId !== (int)$page['space_id'] || $parentId !== ($page['parent_id'] === null ? null : (int)$page['parent_id']);
        $canPlace = $parentId === null ? $access->canEditSpace($spaceId) : $access->canEditPage($parentId);
        if ($placementChanged && !$canPlace) jsonResponse(['error' => 'You do not have permission to move that item there.'], 403);
        try {
            $treeService->validatePlacement($spaceId, $parentId, $id);
        } catch (DomainException $error) {
            jsonResponse(['error' => $error->getMessage()], $error->status());
        }
        $changes = [];
        foreach (['title', 'content', 'is_favorite', 'is_public', 'parent_id', 'space_id', 'position', 'feature_image', 'feature_image_opacity'] as $key) {
            if (!array_key_exists($key, $data)) continue;
            if ($page['kind'] === 'folder' && in_array($key, ['content', 'is_favorite', 'feature_image', 'feature_image_opacity'], true)) continue;
            $value = $data[$key];
            if ($key === 'title') $value = cleanText($value) ?: 'Untitled';
            if ($key === 'content') $value = $sanitizer->clean((string)$value);
            if ($key === 'is_public') {
                $value = $publishing->visibilityFor($page, $value);
                if ($value === null) continue;
            }
            if ($key === 'feature_image' && !$featureImages->clears($value)) {
                $value = $featureImages->normalizePath($value);
                if ($value === null) jsonResponse(['error' => 'A feature image must be an image already uploaded to n3.'], 422);
            } elseif ($key === 'feature_image') {
                $value = null;
            }
            if ($key === 'feature_image_opacity') $value = $featureImages->normalizeOpacity($value);
            if (in_array($key, ['is_favorite', 'space_id', 'position'], true)) $value = (int)$value;
            if ($key === 'parent_id') $value = $value === null ? null : (int)$value;
            $changes[$key] = $value;
        }
        if (!$changes) {
            if (array_key_exists('tags', $data)) replacePageTags($id, $data['tags'], (int)$user['id']);
            if (array_key_exists('references', $data)) (new PageReferenceRepository(db()))->replaceForPage($id, cleanReferences($data['references']), (int)$user['id']);
            jsonResponse(['ok' => true]);
        }
        $firstPublication = (int)$page['is_public'] === 0 && (int)($changes['is_public'] ?? 0) === 1;
        $savedPage = (new PageRepository(db()))->update(
            $id,
            $changes,
            $contentWrite,
            $baseRevision,
            (int)$page['space_id'],
            $spaceId,
            (int)$user['id'],
            $firstPublication,
        );
        if ($savedPage === null) jsonResponse(['error' => 'This page changed in another session. Your local draft was not overwritten.'], 409);
        if (array_key_exists('tags', $data)) replacePageTags($id, $data['tags'], (int)$user['id']);
        if (array_key_exists('references', $data)) (new PageReferenceRepository(db()))->replaceForPage($id, cleanReferences($data['references']), (int)$user['id']);
        jsonResponse(['ok' => true, 'updated_at' => $savedPage['updated_at'], 'content_revision' => $savedPage['content_revision']]);
    }
    if ($method === 'DELETE') {
        pageRow($id, false, true);
        (new PageRepository(db()))->softDeleteTree($id, (int)$user['id']);
        jsonResponse(['ok' => true]);
    }
}

if (($route = $router->matches('POST', '/api/pages/{id}/duplicate')) !== null) {
    $page = pageRow($route['id'], false, true);
    if ($page['kind'] === 'folder') jsonResponse(['error' => 'Folders cannot be duplicated.'], 422);
    $id = (new PageRepository(db()))->duplicate($page, slugify($page['title'] . ' copy'), (int)$user['id']);
    jsonResponse(['id' => $id], 201);
}

if (($route = $router->matches('POST', '/api/pages/{id}/restore')) !== null) {
    pageRow($route['id'], true, true);
    (new TrashRepository(db()))->restoreTree($route['id'], (int)$user['id']);
    jsonResponse(['ok' => true]);
}

if ($path === '/api/trash' && $method === 'GET') {
    jsonResponse((new TrashRepository(db()))->roots($access->accessiblePageIds(true)));
}

if (($route = $router->matches('DELETE', '/api/trash/{id}')) !== null) {
    pageRow($route['id'], true, true);
    (new TrashRepository(db()))->deleteTree($route['id']);
    jsonResponse(['ok' => true]);
}

if ($path === '/api/search' && $method === 'GET') {
    $query = cleanText($_GET['q'] ?? '', 100);
    if ($query === '') jsonResponse([]);
    jsonResponse((new PageRepository(db()))->search($query, $access->accessiblePageIds()));
}

if (($route = $router->matches('GET', '/api/export/{id}')) !== null) {
    $page = pageRow($route['id']);
    $format = ($_GET['format'] ?? 'html') === 'markdown' ? 'markdown' : 'html';
    $safeName = preg_replace('/[^a-z0-9]+/i', '-', strtolower($page['title'])) ?: 'page';
    if ($format === 'markdown') {
        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '.md"');
        echo '# ' . $page['title'] . "\n\n" . (new MarkdownExportService())->convert($page['content']);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '.html"');
        $views = new ViewRenderer(Config::projectRoot() . '/views');
        echo $views->render('page/export', ['page' => $page]);
    }
    exit;
}

$pluginResponse = pluginRegistry()->dispatch($request, $user);
if ($pluginResponse !== null) $pluginResponse->send();

jsonResponse(['error' => 'Route not found.'], 404);
