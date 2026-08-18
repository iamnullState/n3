# n3 plugins

n3 plugins are trusted local code. They execute with the same filesystem, database, session, and application privileges as n3, so install only code you have reviewed. The current plugin contract provides manifest metadata, dashboard widgets, sidebar navigation, structured profile/page-information contributions, authenticated API routes, normalized uploads, private plugin media, signed-in browser CSS/JavaScript, opt-in prefix-scoped public `GET`/`HEAD` routes, and reviewed plugin-owned SQLite migrations.

Before implementing a plugin, document its supported n3 version, permissions, routes, storage, privacy boundaries, installation, verification, and rollback behavior in the plugin's own README. Features such as secret retention, integrated core settings, and background work require a separately reviewed core extension.

## Directory and manifest

Each plugin lives in a directory whose ID contains 1–64 lowercase letters, numbers, underscores, or hyphens and starts with a letter or number. The directory must contain `plugin.json`.

```text
plugins/my-plugin/
  plugin.json
  bootstrap.php   # optional PHP boot hook
  public.php      # optional anonymous hook declared under public.bootstrap
  plugin.css      # optional, listed in the manifest
  plugin.js       # optional, listed in the manifest
  migrations/     # optional, declared sequentially in the manifest
```

Minimal manifest:

```json
{
  "name": "My plugin",
  "version": "1.0.0",
  "enabled": true,
  "contributions": ["profile_tools", "profile_cards", "page_information"],
  "css": ["plugin.css"],
  "js": ["plugin.js"],
  "dashboard": [
    {"title": "Plugin ready", "body": "A dashboard card supplied by my plugin.", "url": "/api/plugins/my-plugin"}
  ]
}
```

Supported manifest fields:

| Field | Contract |
| --- | --- |
| `name` | Display name; defaults to the plugin ID and is limited to 80 characters. |
| `version` | Plugin-supplied version label; defaults to `0.0.0` and is limited to 30 characters. |
| `enabled` | Boolean manifest default. Omitted means enabled; only literal `true` enables a declared value. |
| `css` / `js` | Arrays of existing top-level `.css` or `.js` filenames. At most 20 values from each field are considered. |
| `dashboard` | Array of widget objects using `title`, `body`, and optional absolute-path or HTTP(S) `url` fields. |
| `navigation` | Array of at most five sidebar items using `label`, a plugin-namespace `url`, and an optional short text `icon`. |
| `contributions` | Array containing any of `profile_tools`, `profile_cards`, or `page_information`. A bootstrap may register only explicitly declared slots. |
| `public` | Object with `"bootstrap":"public.php"` and 1–4 unique literal `prefixes`; each prefix is one lowercase URL segment, must not collide with core/another plugin, and opts only that hook into anonymous execution. |
| `migrations` | Ordered array of `migrations/NNN_name.php` paths beginning at `001`; files must exist and remain checksum-immutable after application. |

Unknown manifest fields are ignored. Invalid field types, malformed JSON, and invalid directory IDs produce an `invalid` inventory entry and are not booted. Each CSS filename must be declared under `css` and end in `.css`; each JavaScript filename must be declared under `js` and end in `.js`. Missing and wrong-type declarations are ignored.

Browser assets are served only after the declaring plugin finishes boot as `loaded`. Authenticated requests for disabled, invalid, failed, undeclared, wrong-type, missing, unsupported, or traversal targets receive the same `404` response. Filesystem containment and MIME type are rechecked at delivery time. Successful responses use `Cache-Control: private, no-cache, max-age=0` so authenticated assets may be stored only by a private cache and must be revalidated.

## PHP bootstrap, context, and registry

An optional `bootstrap.php` returns a callable that receives `N3\Plugin\PluginRegistry` while that plugin is being registered. A plugin that needs its own durable tables may also accept `N3\Plugin\PluginContext` as the second argument:

```php
<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginRegistry;
use N3\Plugin\PluginContext;

return static function (PluginRegistry $registry, PluginContext $context): void {
    $registry->dashboardWidget([
        'title' => 'Plugin ready',
        'body' => 'Open the authenticated status endpoint.',
        'url' => '/api/plugins/my-plugin/status',
    ]);

    $registry->route(
        'GET',
        '/api/plugins/my-plugin/items/{item_id}',
        static fn(Request $request, array $user): Response => Response::json([
            'ok' => true,
            'item_id' => $request->route('item_id'),
            'filter' => $request->query('filter', 'all'),
            'user_id' => (int)$user['id'],
        ]),
    );
};
```

`PluginContext` exposes `database(): PDO`, `pluginId(): string`, `appUrl(): string`, and `appName(): string`. Database access is supported only for objects owned and consistently prefixed by that plugin. Reading or changing core/another plugin's tables remains an unsupported dependency. Plugins without migrations may keep the original one-argument bootstrap.

The authenticated context also provides two narrowly scoped core integrations:

| Method | Contract |
| --- | --- |
| `account(int $userId)` | Returns `id`, `display_name`, nullable `profile_url`, nullable `avatar_url`, and current `is_admin`, or `null`. It never returns usernames, biographies, credentials, session state, or avatar storage references. |
| `storeMedia(array $upload)` | Content-inspects one normalized upload, enforces the 250 MB plugin limit and web image/video allowlist, strips metadata with the deployment sanitizer, stores it under the current plugin ID, and returns `url`, `filename`, `kind`, `mime`, and sanitized `size`. |
| `removeMedia(string $filename)` | Removes one safe filename owned by the current plugin media namespace and returns whether it was removed. |

Private plugin media is delivered at `/plugin-media/{plugin-id}/{filename}` only while the plugin is loaded and the viewer is authenticated. Delivery supports byte ranges and uses `private, no-store`. The URL is reserved by core, is not a public hook prefix, and must not be used as permanent public content. A plugin should record upload ownership in its own tables, authorize attachment/reuse, and remove detached media according to its documented retention policy.

`dashboardWidget(array $widget)` applies the same widget normalization as manifest entries. `navigationItem(array $item)` stages up to five items, bounds text, and requires the URL to stay under the registering plugin's own authenticated API namespace. `route(string $method, string $path, callable $handler)` accepts `GET`, `POST`, `PUT`, `PATCH`, or `DELETE`. Every route is required to use the registering plugin's exact `/api/plugins/{own-plugin-id}` root or a descendant. Repeating a method/path pair is rejected instead of overwriting its first handler. Routes with the same method and structural shape, such as `/items/{id}` and `/items/{slug}`, are also duplicates. Namespace and collision violations fail the plugin's complete atomic registration with a fixed diagnostic.

Named parameters use a complete segment such as `{item_id}`. A route may have at most eight segments below its plugin namespace and six parameters. Parameter names start with a lowercase letter, contain only lowercase letters, numbers, and underscores, and are limited to 32 characters. Literal and matched parameter segments are limited to 128 characters and the URL-safe `A-Z`, `a-z`, `0-9`, `.`, `_`, `~`, and `-` characters. Values are percent-decoded before being exposed; malformed escapes, encoded separators, empty segments, and out-of-bound values do not match. A literal route takes precedence over a parameter route when both could match.

The handler receives the captured `N3\Http\Request` and current authenticated user. It may return an `N3\Http\Response`, an array that becomes a JSON response, or a string that becomes a `200 text/html` response. The supported request API is:

| Method | Result |
| --- | --- |
| `method()`, `path()`, `isMutation()` | Captured method/path and mutation classification. |
| `route($name, $default = null)`, `routeParams()` | A matched decoded route value or all route values. |
| `query($name, $default = null)`, `queryParams()` | A captured query value or the complete query map. Values may be strings or arrays. |
| `header($name, $default = null)` | A case-insensitive captured request-header value. |
| `json()` | The decoded JSON object as an array, `[]` for an empty body, or `null` for malformed JSON, excessive nesting, arrays, and scalar roots. |
| `file($name)` | One normalized top-level upload containing only `name`, `type`, `tmp_name`, integer `error`, and integer `size`, or `null` for missing/nested/malformed shapes. Treat client name/type as untrusted and pass the record to an approved content-inspecting store. |

Depending on PHP globals or procedural functions from `src/routes.php` is not a supported plugin API.

## Public routes

Anonymous execution is opt-in and separate from `bootstrap.php`. A manifest may claim up to four non-core literal prefixes and declare only `public.php` as its public hook:

```json
{
  "public": {
    "bootstrap": "public.php",
    "prefixes": ["/short"]
  }
}
```

`public.php` receives `N3\Plugin\PublicPluginRegistry` and `PluginContext`. It may register only `GET` and `HEAD` routes under a claimed prefix, and every handler must return `Response`:

```php
<?php
declare(strict_types=1);

use N3\Http\Request;
use N3\Http\Response;
use N3\Plugin\PluginContext;
use N3\Plugin\PublicPluginRegistry;

return static function (PublicPluginRegistry $registry, PluginContext $context): void {
    $registry->route('GET', '/short/{slug}', static function (Request $request): Response {
        return Response::json(['slug' => $request->route('slug')]);
    });
};
```

Core performs non-executable prefix matching first, applies the stored enablement override, verifies every declared migration is already applied with the recorded checksum, and then executes only the matching plugin's `public.php`. It does not execute the authenticated bootstrap, contributions, or private assets. Disabled, invalid, migration-pending, failed, unmatched, and missing public handlers fail closed. Public hooks receive no user/session/CSRF or private page projection. Register `HEAD` explicitly when supported, return an empty body, and do not treat it as an analytics visit.

Prefixes contain one lowercase segment, cannot use a reserved core route, and must be unique across installed valid plugins. A collision makes both definitions invalid. Public HTML is plugin-owned trusted output: escape every dynamic value, use explicit content/cache/robots headers, and do not assume authenticated plugin CSS/JavaScript can load anonymously.

## Plugin-owned migrations

Relational plugin data requires manifest-declared sequential migrations:

```json
{"migrations":["migrations/001_create_items.php"]}
```

Each migration returns a short name and an `up` callable:

```php
<?php
declare(strict_types=1);

return [
    'name' => 'create example items',
    'up' => static function (\PDO $database): void {
        $database->exec('CREATE TABLE example_items (id INTEGER PRIMARY KEY, label TEXT NOT NULL)');
    },
];
```

Paths must match `migrations/NNN_lowercase_name.php`, begin at `001`, and remain sequential. n3 applies pending files in one immediate transaction during authenticated boot before committing plugin behavior, records their SHA-256 checksums in the core `plugin_migrations` ledger, checks foreign keys, and fails the plugin atomically on error. Public boot never applies migrations. Never edit an applied file: add the next numbered migration. Use a stable plugin-specific prefix for every table/index/trigger, do not read core or other plugin tables, and document forward-only upgrade/backup/removal behavior. The normal SQLite backup includes plugin tables and ledger records; uninstall does not drop them automatically.

## Profile and Page information contributions

Contribution slots are structured, authenticated UI extensions. Declare every used slot in `plugin.json`, then register up to ten handlers per slot during bootstrap:

```php
$registry->profileTool(static fn(array $context): array => [
    'label' => 'Open profile report',
    'url' => '/api/plugins/my-plugin/profile',
]);

$registry->profileCard(static fn(array $context): array => [
    'title' => 'Profile summary',
    'body' => 'Viewer-scoped information supplied by my plugin.',
    'url' => '/api/plugins/my-plugin/profile/summary', // optional
]);

$registry->pageInformationRow(static fn(array $context): array => [
    'label' => 'Review state',
    'value' => $context['page']['can_edit'] ? 'Editable' : 'Read only',
]);
```

Each handler may return one item or a list of up to ten items. Core n3 bounds every string, removes control characters, renders it as escaped text, adds plugin attribution, and accepts contribution links only under the contributing plugin's own `/api/plugins/{plugin-id}` namespace. Profile tools require a valid link. Profile cards require `title` and `body`; their link is optional. Page-information rows require `label` and `value`. Arbitrary HTML, DOM fragments, scripts, images, and core URLs are not contribution output.

Profile handlers receive only:

```text
surface, audience
profile.display_name, profile.profile_url, profile.avatar_url
profile.visibility, profile.is_self, profile.page_counts
```

Page-information handlers receive only:

```text
surface, audience
page.title, page.page_url, page.is_public, page.can_edit, page.can_manage
information.author.{state,name,profile_url,avatar_url}
information.word_count, created_at, first_published_at, updated_at
```

These values are built from the already authorized core projection. They intentionally omit raw user/page IDs, login usernames, biographies, page bodies, collaborator records, storage references, and unfiltered page lists. Treat even this context as private: do not persist it, send it to third parties, or place it in logs without a separately reviewed design.

An exception from one runtime contribution handler omits only that handler's output; remaining handlers and plugins continue. The application log records the plugin ID and slot but not the exception message or context. Bootstrap-time failures still discard the plugin's complete atomic registration. Disabled, invalid, bootstrap-failed, and undeclared plugins contribute nothing.

All current contribution slots are authenticated-only. Signed-in profile pages boot enabled authenticated hooks; anonymous public profiles and public articles never execute `bootstrap.php` or serialize contributions. An explicitly declared prefix may execute only that plugin's separate `public.php`; there is no anonymous contribution API.

Mutation handlers use the same request object. Return a `Response` when the status or headers matter, or return an array for a `200` JSON response:

```php
$registry->route(
    'POST',
    '/api/plugins/my-plugin/items/{item_id}/events',
    static function (Request $request, array $user): Response|array {
        $payload = $request->json();
        if ($payload === null) return Response::json(['error' => 'Expected a JSON object.'], 400);
        return [
            'ok' => true,
            'item_id' => $request->route('item_id'),
            'event' => is_string($payload['event'] ?? null) ? $payload['event'] : 'updated',
            'user_id' => (int)$user['id'],
        ];
    },
);
```

Browser code must obtain the current CSRF token before calling that route:

```js
const bootstrap = await fetch('/api/bootstrap').then(response => response.json());
const response = await fetch('/api/plugins/my-plugin/items/example/events', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': bootstrap.csrfToken,
  },
  body: JSON.stringify({event: 'reviewed'}),
});
if (!response.ok) throw new Error((await response.json()).error || 'Plugin request failed.');
```

## Authentication and CSRF

Plugin routes are dispatched only after n3 authenticates the request. Anonymous `/api/plugins/*` requests receive the core JSON `401` response before plugin dispatch.

The core CSRF guard runs before all authenticated plugin `POST`, `PUT`, `PATCH`, and `DELETE` handlers. Browser code must send the current `/api/bootstrap` token in the `X-CSRF-Token` header. A missing or invalid token receives the core JSON `403` response. Plugin handlers remain responsible for capability checks beyond authentication, such as requiring `is_admin` or access to a specific page or space.

## Security boundaries

Plugins are trusted, not sandboxed. A PHP bootstrap executes inside the n3 process and can access its filesystem, environment, database connection, session, and loaded code. A declared JavaScript asset runs with the same origin and DOM access as the main application, and CSS can alter any signed-in interface. Route namespaces and asset allowlists prevent accidental registration/delivery conflicts; they do not isolate malicious PHP, JavaScript, or CSS.

Plugin authors and reviewers must therefore ensure that:

- every installed file and dependency is reviewed, pinned, and obtained from a trusted source;
- handlers validate type, length, allowed values, and resource identifiers for every route, query, header, and JSON input;
- authenticated users receive only data they are allowed to view or change; core authentication is not resource authorization;
- mutations rely on the core CSRF gate and still enforce administrator, owner, editor, or plugin-specific permissions as needed;
- HTML returned as a string or `Response` is safely escaped or sanitized, because plugin HTML is not sanitized by the registry;
- database work uses prepared statements and transactions without depending on undocumented table details;
- errors and logs omit page content, credentials, cookies, CSRF tokens, environment secrets, filesystem paths, and raw exception output;
- browser assets do not load unreviewed remote code or send private wiki data to third parties.

Prefer JSON responses and existing browser rendering patterns. If a plugin must render HTML, set an explicit content type and treat every dynamic value as untrusted.

## Lifecycle and failure behavior

The complete lifecycle is evaluated independently for each request:

1. `discover()` reads and validates manifests in sorted directory order without registering contributions or requiring plugin PHP. Each definition begins as `enabled`, `disabled`, or `invalid` from its manifest.
2. After authentication, stored database overrides are applied. `effective_enabled` is the override when present and otherwise the manifest default.
3. API mutations, including plugin routes and administration, must pass the core CSRF guard before executable plugin boot. A plugin enable/disable administration request is persisted and exits without booting plugin PHP.
4. `boot()` executes each valid effectively enabled definition inside an isolated registry transaction. Manifest widgets and PHP contributions remain staged until bootstrap completes.
5. Successful definitions are committed and become `loaded`. Thrown bootstrap exceptions discard the complete registration, become `failed`, receive a fixed diagnostic without exception or filesystem details, and are written to the application error log. Loading then continues with the next plugin.

`inventory()` returns normalized identity, manifest enablement, an optional database override, effective enablement, status, and the sanitized diagnostic. Internal manifest data and filesystem paths are not included. Discovery and boot are idempotent for the lifetime of the manager.

The application performs non-executable discovery during global bootstrap. Authenticated hooks boot only after authentication, and API mutations must pass the core CSRF guard first. Separately, a request under a valid claimed public prefix may execute only the matching effectively enabled `public.php` after migration readiness is verified. Health, public profiles/articles/media, setup, login, anonymous protected requests, anonymous logout, rejected mutations, and paths outside a claimed prefix do not execute plugin PHP. During authenticated boot, pending migrations run before registration and manifest widgets are staged before the optional PHP callable.

The source-tree default plugin directory is `plugins/`. Tests and isolated development processes may set `N3_PLUGIN_DIR` to an alternate trusted directory. Production Compose sets it to `/var/www/data/plugins`, which persists uploaded plugins in the `n3_data` volume. On startup, Compose also mounts the repository directory read-only and imports a plugin when its ID is not already present in persistent storage; this preserves plugins from installations that predate ZIP uploads.

## Compatibility contract

The base plugin contract was introduced in n3 `0.3.0`; prefix-scoped public hooks and plugin migrations require the later contract containing core migration `005`. Sidebar navigation was added during that contract's lifecycle. The safe account projection, normalized upload accessor, and authenticated plugin media store require n3 `0.6.0`. n3 has no plugin dependency resolver or compatibility-range field. The manifest `version` is a display label; n3 does not interpret it as a compatibility guarantee. Authors should test against the exact n3 release they support and state that release in their notes.

The supported plugin-facing contract is limited to:

- the documented `plugin.json` fields and normalization limits;
- a `bootstrap.php` callable receiving `N3\Plugin\PluginRegistry`;
- an optional second `N3\Plugin\PluginContext` bootstrap argument and its documented accessors;
- a separately declared `public.php` callable receiving `N3\Plugin\PublicPluginRegistry` and `PluginContext`;
- sequential manifest-declared plugin migrations and plugin-prefixed table access through the context database;
- `dashboardWidget()`, `navigationItem()`, and `route()` with the documented route constraints;
- `PluginContext::account()`, `storeMedia()`, and `removeMedia()` with the documented identity, authorization, privacy, and retention boundaries;
- `Request::file()` for one normalized top-level upload;
- manifest-declared `profileTool()`, `profileCard()`, and `pageInformationRow()` structured handlers with their documented allowlisted contexts;
- the documented `N3\Http\Request` and `N3\Http\Response` methods;
- the authenticated user array fields needed by examples (`id` and `is_admin` when authorization requires it);
- declared top-level CSS/JavaScript delivery through `/plugin-assets/{plugin}/{filename}` and authenticated media delivery through `/plugin-media/{plugin}/{filename}`;
- database-backed effective enablement and the administrator API described below.

Core procedural functions, PHP globals, controllers, repositories, services, database tables, HTML structure, CSS class names, and undocumented object fields are internal unless this guide explicitly says otherwise. They may change without plugin compatibility treatment. Unknown manifest fields are currently ignored for forward tolerance, but they are not an extension mechanism. Do not require another plugin's boot order or contributions; directory ordering is deterministic for diagnosis, not a dependency API.

Keep the plugin directory ID stable across upgrades. It owns the API namespace, asset URLs, and persistent enablement override. Reusing an ID for a different plugin can inherit the previous plugin's stored override.

## Reference implementation

The tracked [reference plugin](../examples/plugins/reference-plugin/README.md) is a complete, non-production example. It is intentionally outside `plugins/`, defaults to disabled, and therefore cannot execute in a normal installation. Its manifest, authenticated and public PHP hooks, CSS, JavaScript, dashboard widget, profile tools/cards, Page information row, parameterized authenticated read route, CSRF-gated JSON mutation route, and error response are kept together for copying and adaptation during local development.

Run its focused contract test with:

```bash
php tests/reference_plugin.php
```

## Installation, upgrade, and removal

Administrators can install a reviewed ZIP archive from **Plugin management**. The archive must contain either one valid plugin directory or a top-level `plugin.json`; top-level archives derive their plugin ID from the ZIP filename. Uploads reject traversal paths, oversized expansions, malformed manifests, and replacement of an existing plugin ID.

For a new plugin:

1. Review its PHP, JavaScript, CSS, dependencies, and data-handling behavior.
2. Confirm its directory ID is unique and its documented n3 compatibility includes the running application version.
3. Upload the complete plugin ZIP in **Plugin management**. Prefer a manifest default of `"enabled": false` for first installation. Source checkouts may still copy a plugin into `plugins/{plugin-id}` for local development.
4. Reload n3 and open **Plugin management** as an administrator. Confirm the expected name, version, capabilities, manifest state, and absence of diagnostics.
5. Enable the plugin. The dashboard performs the required full-page reload.
6. Exercise its read and mutation routes with a non-administrator account when the plugin supports them, and verify its authorization rules.

For an upgrade, disable the plugin and complete the resulting reload before replacing files. Take a current n3 backup, replace the directory atomically, then reload, inspect diagnostics/version/capabilities, enable, and test again. Declared forward migrations apply during that authenticated boot. Roll back by disabling the plugin and restoring both reviewed prior files and a compatible database backup when the older code cannot read the newer schema. Migration files are forward-only and uninstall never drops plugin tables automatically.

Before removing a plugin, disable it and reload so its browser assets and server contributions are gone. Removing the directory makes it disappear from inventory, but an existing database override remains associated with that ID and will apply if the same ID is installed again.

## Administrator API and persistent enablement

The `enabled` field in `plugin.json` is the default. Migration `002` stores an administrator's explicit override in SQLite without modifying plugin files. On each authenticated request, n3 applies stored overrides before deciding which plugins may boot.

`GET /api/plugins` is administrator-only and returns:

```json
{
  "plugins": [
    {
      "id": "my-plugin",
      "name": "My plugin",
      "version": "1.0.0",
      "enabled": true,
      "manifest_enabled": true,
      "override_enabled": null,
      "effective_enabled": true,
      "status": "loaded",
      "diagnostic": null,
      "capabilities": {
        "php_bootstrap": true,
        "dashboard_widgets": 1,
        "navigation_items": 1,
        "css_assets": 1,
        "js_assets": 1,
        "profile_tools": true,
        "profile_cards": true,
        "page_information": true
      }
    }
  ]
}
```

`enabled` remains a compatibility alias for `manifest_enabled`. `override_enabled` is `null` until an administrator explicitly chooses a state. `effective_enabled` is the override when present and otherwise the manifest default. Status is one of `enabled`, `disabled`, `invalid`, `loaded`, or `failed`; diagnostics are fixed, sanitized strings or `null`.

`PUT /api/plugins/{plugin-id}` accepts a JSON object with a required boolean state, for example `{"enabled": false}`. It requires an administrator session and the normal `X-CSRF-Token` header. A successful response contains the updated `plugin` record and `"reload_required": true`. The update is persisted before plugin boot, allowing a failing plugin to be disabled without executing it. The dashboard client must perform a full-page reload before treating browser assets or runtime contributions as updated.

Anonymous requests receive the normal API `401`; authenticated non-administrators receive `403`. Unknown plugins receive `404`, invalid manifests cannot have their state changed and receive `409`, malformed JSON receives `400`, and a missing or non-boolean `enabled` field receives `422`. Detailed inventory and diagnostics are never included in `/api/health`.

Administrators can open **Plugin management** from the dashboard sidebar. The dialog shows version, manifest default, stored override, effective state, current load status, sanitized diagnostic, and declared PHP/widget/CSS/JavaScript capabilities. Invalid plugins remain visible but cannot be toggled until their manifest is fixed. A successful enable or disable action first flushes a pending page save and then performs a full-page reload, ensuring stale plugin scripts and styles are removed from the browser runtime.

## Diagnostics and status interpretation

The administrator inventory is the supported diagnostic surface. `/api/health` intentionally reports only application health/version and never plugin identities or failures.

| Status | Meaning | Contributions available? | Next action |
| --- | --- | --- | --- |
| `enabled` | The definition is valid and effectively enabled but has not completed boot in this manager lifecycle. | No | Allow authenticated boot to complete. |
| `disabled` | The manifest default or database override makes the plugin inactive. | No | Enable it only after review. |
| `invalid` | Directory naming, JSON shape, or a manifest field failed validation. PHP was not executed. | No | Fix the manifest diagnostic, then reload. |
| `loaded` | Manifest and PHP registration completed and committed atomically. | Yes | Verify routes, widgets, and assets. |
| `failed` | Registration or bootstrap threw; all staged contributions were discarded. | No | Read the sanitized diagnostic and inspect application logs. |

Known registration boundary failures, such as foreign namespaces and route collisions, use fixed actionable diagnostics. Arbitrary bootstrap failures expose only `Plugin bootstrap failed. Check the application log.` to administrators. The application log records the plugin ID and exception message for the operator, but inventory never exposes stack traces, source paths, private content, or credentials. Plugin authors should throw concise exceptions that identify the failed operation without embedding sensitive values.

Capability counts and contribution booleans describe validated declarations and bootstrap presence, not permission or safety. A `php_bootstrap` value of `true` means executable PHP exists; it does not mean boot succeeded. A contribution boolean means the slot was declared, not that a handler registered or returned output.

## Troubleshooting

| Symptom | Likely cause | Check |
| --- | --- | --- |
| Plugin does not appear in inventory | Wrong directory level, missing `plugin.json`, or the wrong configured plugin root | Confirm `plugins/{plugin-id}/plugin.json` exists on the host and reload. |
| Status is `invalid` | Invalid directory ID, malformed JSON, or a field with the wrong type | Read the fixed diagnostic; validate JSON and the manifest field table above. |
| Status is `disabled` unexpectedly | Manifest default is false or a stored override takes precedence | Compare `manifest_enabled`, `override_enabled`, and `effective_enabled`. |
| Status is `failed` | Bootstrap exception, foreign route namespace, or duplicate route shape | Read the sanitized diagnostic, then inspect application logs for the plugin ID. |
| Route returns `401` | No valid signed-in session | Sign in before calling any plugin route. |
| Mutation returns `403` | Missing/expired CSRF token or plugin-specific authorization failure | Refresh `/api/bootstrap`, send `X-CSRF-Token`, and verify the handler's permission rules. |
| Route returns `404` | Plugin is not loaded, method/path differs, or a parameter violates bounds | Check effective status, namespace, HTTP method, segment count, encoding, and allowed characters. |
| Asset returns `404` | Plugin is not loaded or the file is missing, undeclared, wrong type, nested, or traversal-like | Keep the file at plugin root, declare it under the matching `css`/`js` field, and reload. |
| Private media returns `404` | The plugin is disabled/failed, the viewer is signed out, or the filename/plugin namespace is invalid | Confirm loaded status, authentication, the returned `storeMedia()` URL, and retained media. |
| Upload returns `422`/`500` | Missing/nested upload, unsupported inspected content, 250 MB overflow, or unavailable metadata sanitizer | Use `Request::file()`, do not trust browser MIME/name, and verify the deployment includes ExifTool. |
| Widget or asset looks stale | Browser still has the prior runtime after a file/state change | Perform a full-page reload; disable/enable changes do this automatically. |
| One plugin is absent but others load | Atomic failure isolation discarded only the failing plugin | Inspect that plugin's status/log entry; do not assume global plugin boot stopped. |
| One profile card or information row is absent | Its handler threw or returned an invalid structured item | Inspect the application log for the plugin ID and slot; other contributions remain available. |
| Reinstalled plugin has an unexpected state | A database override persisted for the reused directory ID | Set the intended state in **Plugin management** and reload. |

## Pre-deployment review checklist

- [ ] The plugin source, dependencies, licenses, and release origin have been reviewed.
- [ ] The plugin declares a stable unique ID, display name, version, and conservative default enablement.
- [ ] The claimed n3 version has been tested; no unsupported globals, procedural helpers, internal classes, schema details, or plugin ordering are required.
- [ ] Every route stays under `/api/plugins/{own-plugin-id}` and uses bounded, uniquely named parameters.
- [ ] Every public hook claims only reviewed unique prefixes, registers only `GET`/`HEAD`, escapes output, fails closed, and does not assume private assets/session state.
- [ ] Every plugin migration is sequential, checksum-immutable, transactional, plugin-prefixed, forward-only, backup-tested, and independent of core/other-plugin tables.
- [ ] Every input has explicit type, length, character, and allowed-value validation.
- [ ] Every upload uses `Request::file()`, content inspection, a documented size/type policy, fail-closed metadata handling, resource ownership, authenticated delivery, and cleanup/retention rules.
- [ ] Every profile/Page information handler has a matching manifest declaration, uses only the documented context, and returns bounded structured text with plugin-namespace URLs.
- [ ] Read routes enforce resource visibility; mutations enforce administrator/owner/editor/plugin-specific permissions in addition to core CSRF.
- [ ] JSON failures use safe status codes/messages, HTML output is escaped or sanitized, and redirects/headers cannot be injected.
- [ ] Logs and errors contain no credentials, session/CSRF values, private content, secrets, raw stack traces, or filesystem paths.
- [ ] CSS and JavaScript are reviewed as same-origin privileged code and do not leak data or load unapproved remote code.
- [ ] Every browser asset is a top-level declared `.css` or `.js` file; missing, undeclared, wrong-type, and traversal requests were tested.
- [ ] Bootstrap failure leaves no partial routes, widgets, or assets, and the diagnostic remains useful but sanitized.
- [ ] A current n3 backup exists, plugin-specific data changes have a rollback plan, and removal behavior is documented.
- [ ] The plugin was installed disabled, inspected in **Plugin management**, enabled with a full reload, and tested with administrator and non-administrator accounts.
- [ ] `php tests/plugin_lifecycle.php`, `php tests/plugin_request_lifecycle.php`, relevant plugin tests, static checks, and browser smoke tests pass together.

## Verify the baseline

Tracked fixtures cover enabled, disabled, malformed JSON, invalid schema, invalid directory ID, foreign-namespace and duplicate-route registration, bootstrap failure, and post-failure healthy plugins. Run their focused characterization test with:

```bash
php tests/plugin_lifecycle.php
php tests/plugin_contribution_service.php
php tests/plugin_request_lifecycle.php
php tests/reference_plugin.php
php tests/slug_plugin.php
```

The focused matrix divides responsibilities deliberately:

- `plugin_lifecycle.php` covers deterministic discovery, invalid manifests, atomic failure isolation, namespace ownership, structural duplicate routes, exact route-parameter bounds, literal precedence, request accessors, contribution-handler isolation, asset allowlists, and failed-plugin contribution removal.
- `plugin_contribution_service.php` covers required declarations, allowlisted viewer-scoped contexts, structured output normalization, plugin-namespace links, and public omission.
- `plugin_request_lifecycle.php` covers public/core independence, authenticated profile/page contributions, authentication and administrator authorization, CSRF ordering, persistent overrides in both directions, invalid enablement requests, sanitized diagnostics, parameterized dispatch, declared asset delivery, and encoded slash, backslash, double-encoding, wrong-type, undeclared, disabled, and failed asset rejection.
- `extensions.php` covers the core migration ledger through `005`, including plugin migration storage, and default-versus-override persistence at the repository/manager boundary.
- `reference_plugin.php` boots the non-production example through an explicit alternate plugin root and exercises every contribution and handler API it documents.
- `slug_plugin.php` applies a real plugin migration and covers administrator CRUD, validation, public redirect/preview behavior, aggregate privacy, tracking, stale writes, tombstones, and disabled-state failure.
- The Playwright workflow covers administrator rendering and the mandatory full-page reload after enablement changes.
