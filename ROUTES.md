# n3 Route Inventory

This document records the stable route contract captured from the original procedural dispatcher and preserved through the MVC extraction. Keep these paths, methods, access rules, status codes, and response formats compatible unless a later task explicitly changes the public contract.

## Access and response conventions

- Security headers are applied to every response before dispatch.
- `/api/health` is handled before session startup and currently accepts any HTTP method.
- Plugin manifests are discovered without executing plugin PHP during application bootstrap; executable plugin boot occurs only after private-route authentication and mutation CSRF validation.
- Public routes start a secure session but do not require an authenticated user.
- An anonymous request to a protected `/api/*` route receives JSON `401`.
- An anonymous request to another protected route redirects to `/login`, or `/setup` when no account exists.
- Authenticated `POST`, `PUT`, `PATCH`, and `DELETE` requests below `/api/` require the `X-CSRF-Token` header.
- The `/setup` and `/login` HTML forms validate their session CSRF form field.
- Unmatched authenticated requests receive a JSON `404`, including unmatched non-API paths and unsupported methods.
- Resource lookup helpers generally produce JSON `404` responses, including when called from an HTML route.
- Authenticated page-detail JSON uses an explicit field allowlist and a viewer-scoped `page_information` projection; raw author/editor IDs, account fields, and collaborator records are never returned. Loaded plugins may append bounded structured `plugin_rows` only through an explicitly declared slot.
- `feature_image` accepts only a `/media/{40-hex}.{jpg|png|gif|webp|avif|bmp}` path already produced by `POST /api/media`; any other value is rejected with `422`, `null` or an empty string clears it, and folders ignore the field. `feature_image_opacity` is clamped to 40–60 and defaults to 50.

Access abbreviations used below:

- `Public`: no account or login required.
- `Guest`: intended for an unauthenticated browser; authenticated owners are redirected.
- `User`: a current authenticated session is required; resource-level viewer/editor/owner checks still apply.
- `Owner`: the current user must own the requested space or the space containing the requested page.
- `Admin`: the current account must have local account-administration permission.
- `Setup`: available only before the first owner account exists.
- `Profile-scoped`: self always; another signed-in user for members/public profiles; anonymous users only for public profiles.

## System and public routes

| Method | Path | Access | CSRF | Success response | Current handler | MVC destination |
| --- | --- | --- | --- | --- | --- | --- |
| `ANY` | `/api/health` | Public | No | JSON `200` with `status` and semantic `version` | Inline dispatcher | `HealthController::show` |
| `GET` | `/` | Public/User | No | Anonymous public-home HTML or authenticated redirect to `/dashboard` | `PublicController` / inline redirect | `PublicHomeController::index` / `AppController::index` |
| `GET` | `/dashboard` | User | No | Dashboard/application-shell HTML with `no-store` | Inline dispatcher | `AppController::index` |
| `GET` | `/public` | Public | No | Public-home/search HTML | `renderPublicHome` | `PublicHomeController::index` |
| `GET` | `/tags` | Public | No | Public tag-directory HTML | `renderPublicTags` | `PublicTagController::index` |
| `GET` | `/p/{slug}` | Public | No | Public page HTML with viewer-safe Page information; `404` text when absent | `renderPublicPage` | `PublicPageController::show` |
| `GET` | `/u/{slug}` | Profile-scoped | No | Viewer-filtered profile HTML; public responses are canonical/indexable and omit plugins without booting them, while signed-in responses may include declared structured plugin tools/cards and use `no-store`/`noindex`; missing/denied/malformed slugs share a generic `404` | `ProfilePageController` | `ProfilePageController::show` |
| `GET` | `/public/{id}` | Public | No | Permanent redirect to `/p/{slug}`; `404` text when absent | `redirectLegacyPublicPage` | `PublicPageController::legacyRedirect` |
| `GET` | `/sitemap.xml` | Public | No | XML sitemap | `renderSitemap` | `SitemapController::index` |
| `GET` | `/feed.xml` | Public | No | RSS XML | `renderFeed` | `FeedController::index` |
| `GET` | `/index.php` | Owner | No | Application-shell HTML with `no-store` | Inline dispatcher | `AppController::index` |
| `GET` | `/index.html` | Owner | No | Application-shell HTML with `no-store` | Inline dispatcher | `AppController::index` |
| `GET` | `/page/{id}` | User/viewer | No | `302` compatibility redirect to `/page/{slug}` | Inline dispatcher | `PageController::editorRedirect` |
| `GET` | `/page/{slug}` | User/viewer | No | Application-shell HTML with `no-store` and `noindex`; `404` when inaccessible | Inline dispatcher | `PageController::editor` |
| `GET` | `/preview/{id}` | User/viewer | No | Private preview HTML with viewer-safe Page information, `no-store`, and `noindex` | Inline dispatcher | `PreviewController::show` |
| `GET` | `/plugin-assets/{plugin}/{filename}` | User | No | Declared CSS or JavaScript from a loaded plugin with private revalidation caching | `PluginManager` | `PluginAssetController::show` |
| `GET`, `HEAD` | `/avatar/{slug}` | Profile-scoped | No | Authorized avatar bytes with `no-store`; identical `404` when missing or hidden | `ProfileAvatarService` | `ProfileController::avatar` |

## Authentication routes

| Method | Path | Access | CSRF | Success response | Important alternate responses | MVC destination |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/setup` | Setup | Token issued | Setup HTML | `302 /login` when an account exists | `SetupController::form` |
| `POST` | `/setup` | Setup | Form token | Creates administrator/session, then `303 /dashboard` | `422` validation HTML; `303 /login` on setup race | `SetupController::create` |
| `GET` | `/login` | Guest | Token issued | Login HTML | `303 /dashboard` when signed in; `303 /setup` without account | `LoginController::form` |
| `POST` | `/login` | Guest | Form token | Creates session, then `303 /dashboard` | `401` invalid credentials; `429` rate limit | `LoginController::create` |
| `POST` | `/logout` | Owner/Public | API token for owner | JSON `200` with `ok`; destroys owner session | Anonymous request is idempotent and returns `200` without CSRF | `SessionController::destroy` |

## Authenticated API routes

All mutation routes in this table use JSON request bodies unless noted otherwise and require the shared API CSRF header.

| Method | Path | Purpose | Success response | Important alternate responses | MVC destination |
| --- | --- | --- | --- | --- | --- |
| `GET` | `/api/bootstrap` | Load accessible resources, permissions, sharing/dashboard/plugin metadata, and CSRF token | JSON `200` object | — | `BootstrapController::show` |
| `GET` | `/api/diagnostics` | Run sanitized application, storage, backup, and database checks for an administrator | JSON `200` with `diagnostics` | `403` | `SystemDiagnosticsController::index` |
| `GET` | `/api/profile` | Load the current user's editable profile settings and safe avatar/profile URLs | JSON `200` profile projection | `404` | `ProfileController::show` |
| `PUT` | `/api/profile` | Update display name, username, biography, and profile visibility | JSON `200` profile projection; rotated CSRF token after a username change | `403`, `409`, `422` | `ProfileController::update` |
| `POST` | `/api/profile/avatar` | Upload and inspect the current user's avatar using multipart field `avatar` | JSON `201` with safe avatar metadata and authorized URL | `409`, `422` | `ProfileController::storeAvatar` |
| `DELETE` | `/api/profile/avatar` | Remove the current user's avatar | JSON `200` with empty avatar state | `409` | `ProfileController::removeAvatar` |
| `PUT` | `/api/account` | Change username and optionally password | JSON `200` with username and new CSRF token | `403`, `409`, `422` | `AccountController::update` |
| `POST` | `/api/account/invalidate-sessions` | Invalidate other sessions | JSON `200` with new CSRF token | `403` | `AccountController::invalidateSessions` |
| `POST` | `/api/spaces` | Create a space | JSON `201` with id | `422` | `SpaceController::create` |
| `PUT` | `/api/spaces/{id}` | Update a space | JSON `200` with `ok` | `422` | `SpaceController::update` |
| `DELETE` | `/api/spaces/{id}` | Delete a space | JSON `200` with `ok` | `409` when it is the last space | `SpaceController::delete` |
| `POST` | `/api/pages` | Create a page or folder | JSON `201` with id | `404`, `409`, `422` from hierarchy validation | `PageController::create` |
| `GET` | `/api/pages/{id}` | Load a page/folder, tags, references, related pages, access flags, and viewer-safe Page information | JSON `200` object | `404` | `PageController::show` |
| `PUT` | `/api/pages/{id}` | Update content, metadata, feature image, location, tags, or references | JSON `200` with save metadata | `403`, `404`, `409`, `422`, `428` | `PageController::update` |
| `DELETE` | `/api/pages/{id}` | Soft-delete a page/folder subtree | JSON `200` with `ok` | `404` | `PageController::delete` |
| `POST` | `/api/pages/{id}/duplicate` | Duplicate a page | JSON `201` with id | `404`, `422` for folders | `PageController::duplicate` |
| `POST` | `/api/pages/{id}/restore` | Restore a trashed subtree | JSON `200` with `ok` | `404` | `TrashController::restore` |
| `GET` | `/api/pages/{id}/revisions` | List up to 100 revisions | JSON `200` array | `404`, `422` for folders | `RevisionController::index` |
| `GET` | `/api/pages/{id}/revisions/{revision}` | Load a revision snapshot | JSON `200` object | `404` | `RevisionController::show` |
| `POST` | `/api/pages/{id}/revisions/{revision}/restore` | Restore a snapshot as a new revision | JSON `200` with revision metadata | `404`, `409`, `422`, `428` | `RevisionController::restore` |
| `PUT` | `/api/tree/reorder` | Atomically move and order a subtree | JSON `200` with `ok` | `404`, `409`, `422` | `TreeController::reorder` |
| `GET` | `/api/trash` | List top-level trashed items | JSON `200` array | — | `TrashController::index` |
| `DELETE` | `/api/trash/{id}` | Permanently delete a trashed subtree | JSON `200` with `ok` | `404` | `TrashController::delete` |
| `GET` | `/api/search?q={query}` | Search active pages | JSON `200` array; empty query returns `[]` | — | `SearchController::index` |
| `GET` | `/api/export/{id}?format={html\|markdown}` | Download a page export | HTML or Markdown attachment | `404` | `ExportController::show` |
| `POST` | `/api/collaboration/users` | Create a local collaborator account | JSON `201` with id and username | `403`, `422` | `CollaborationController::createUser` |
| `GET` | `/api/shares?resource_type={space\|page}&resource_id={id}` | List direct grants on an owned resource | JSON `200` array | `403` | `ShareController::index` |
| `POST` | `/api/shares` | Grant or update viewer/editor access | JSON `201` with id | `403`, `422` | `ShareController::store` |
| `DELETE` | `/api/shares/{id}` | Revoke an owned grant | JSON `200` with `ok` | `404` | `ShareController::delete` |
| `GET` | `/api/plugins` | List plugin defaults, overrides, effective state, load status, and sanitized diagnostics | JSON `200` with `plugins` | `403` | `PluginAdminController::index` |
| `PUT` | `/api/plugins/{plugin}` | Persist a boolean plugin enablement override; full reload required | JSON `200` with `plugin` and `reload_required` | `400`, `403`, `404`, `409`, `422` | `PluginAdminController::update` |
| `ANY` | `/api/plugins/{plugin}[/…]` | Dispatch a trusted plugin route with bounded named segment parameters | Plugin-defined response | Plugin-defined | `PluginRegistry` |

## Shared MVC concerns exposed by the inventory

The MVC foundation must provide these cross-cutting behaviors before controllers are extracted:

- Security-header middleware for every response, including errors and redirects
- Secure session startup and current-owner resolution
- Guest/owner/setup access guards
- Separate form-CSRF and JSON-header-CSRF validation
- JSON, HTML, XML, attachment, and redirect response types
- Consistent exception-to-response handling without leaking details
- Typed route parameters for numeric IDs, revisions, and public slugs
- Database transaction support for tree moves, content saves, and revision restores

## Inventory maintenance

When a route is added or intentionally changed:

1. Update this inventory in the same commit.
2. Add or update its characterization/integration test.
3. Record any compatibility break in the release notes and migration guidance.

## Characterization coverage

`tests/auth_smoke.php` exercises every route/method combination in this inventory against a fresh database. It explicitly protects the less obvious dispatcher contracts added during the M1 audit:

- Method-independent health responses and security headers
- Public-page not-found behavior and anonymous logout idempotency
- All three owner application-shell entry paths
- Both HTML and Markdown attachment exports
- Space update and final-space deletion protection
- Page duplication, trash listing, restore, and permanent deletion
- Authenticated login redirection and the JSON fallback `404`
- Profile projection/update, password-confirmed username changes, multipart avatar upload/removal, and public/private avatar delivery

`tests/e2e/n3.spec.js` adds browser-level coverage for autosave, local draft recovery, revision restore, dialogs, themes, drag-and-drop, and responsive directory behavior. `tests/backup_roundtrip.php` separately protects archive validation, data relationships, revision retention, fallback snapshots, and rotation.
