# Changelog

## Unreleased

- Added the disabled-by-default Hub forum plugin with administrator-managed categories, pinned discussions, flat replies, owner/administrator CRUD, current profile avatars and ADMIN badges, a familiar rich-text editor, and responsive authenticated UI.
- Extended the reviewed plugin contract with bounded sidebar navigation, safe collaboration-account identity projections, normalized single-file uploads, and authenticated plugin-namespaced media up to 250 MB with content inspection and fail-closed metadata stripping.
- Added an optional per-page feature image: an uploaded n3 photo rendered as a full-container backdrop behind the post header that fades top-to-bottom into the page surface where the tags and body begin. Editors can add, replace, adjust fade, and remove it inline; the same backdrop appears in read mode, private previews, and published pages, and the image is advertised as `og:image`.
- Defined the privacy-first profile and authorship contract, including stable profile URLs, viewer-specific identity projections, avatar rules, author fallbacks, and exact owned/shared/published page filtering.
- Added schema migration `003` for private-by-default profile metadata, stable profile slugs, page authorship, last-editor identity, and first-publication timestamps with deterministic legacy backfills.
- Added viewer-filtered profile repositories and services, plus actor-aware page mutations that preserve original authorship, track meaningful edits, and retain the initial publication timestamp.
- Added privacy-scoped avatar storage with strict server-side image validation, bounded dimensions and size, opaque filenames, safe replacement/removal, and checksummed backup/restore support.
- Added authenticated self-service profile APIs and a responsive Profile & Account panel with live identity preview, visibility controls, secure avatar management, immutable profile URLs, and password-confirmed username changes.
- Added canonical profile pages with separate self-owned, shared, and published groups, AccessService-filtered collaborator views, public-profile opt-in, anonymous published-page filtering, and indistinguishable denied/missing responses.
- Added privacy-safe Page information panels to authenticated editor/read views, private previews, and public articles with permitted author identity, profile/avatar links, live word counts, provenance dates, and non-linking private/unknown author fallbacks.
- Replaced broad page-detail responses with explicit authenticated/public projection allowlists, canonical UTC provenance dates, and public-directory queries that omit unrelated private nodes and redact private page-ancestor titles and URLs.
- Added manifest-declared trusted-plugin slots for signed-in profile tools/cards and Page information rows with viewer-authorized contexts, structured escaped output, per-handler failure isolation, and complete public-response omission.
- Expanded profile release coverage across repository filtering, CSRF/API privacy, username/session rotation, avatar backup boundaries, schema-2 profile/authorship upgrades, publication metadata, failed/disabled plugin contributions, signed-in profile accessibility, and real-browser collaborator filtering.
- Flattened the authenticated top bar and dashboard hero onto the main content background in every built-in theme, removing the translucent yellow and gradient treatments.
- Added bounded, persistent desktop workspace-directory resizing with pointer and keyboard controls while preserving the mobile drawer layout.
- Added persistent desktop workspace-directory collapse and reveal controls with accessible state and focus restoration, independent of the mobile drawer.
- Expanded browser coverage for sidebar width limits, saved-state normalization, long navigation labels, collapse restoration, and desktop/mobile breakpoint transitions.
- Added a one-time browser migration from legacy `folio.*` preferences and local drafts to the current `n3.*` storage keys without overwriting newer values.
- Added administrator-only system diagnostics for version, writable storage, backup visibility, schema version, SQLite integrity, and foreign keys.
- Hardened desktop and mobile loading, empty, startup-error, offline-edit, and save-conflict states with persistent recovery actions and automatic save retry after reconnecting.
- Preserved tables, callouts, internal and external links, Unicode, and nested lists in standalone HTML and Markdown exports, with dedicated format and download-contract coverage.
- Added stable `/page/{slug}` editor URLs with legacy numeric redirects, private no-index headers, and compatible internal-link publishing.
- Improved keyboard navigation and focus restoration for workspace menus, directory disclosures, contents, references, search results, and public media; added control-state labels, stronger secondary-text contrast, and reduced-motion handling across public, authenticated, and authentication views.
- Added automated WCAG A/AA browser checks for light and dark authentication, workspace, editor menus and dialogs, authenticated and public mobile directories, public indexes, and published pages.
- Removed superseded procedural wrappers in favor of the tested request, response, configuration, authentication, repository, export, and controller boundaries; expanded CI to run the complete repository and service test set.

## 0.6.0 — 2026-08-15

- Packaged the current profile, application-settings, plugin-migration, and Hub work as the local n3 0.6.0 deployment baseline.

## 0.3.0 — 2026-07-25

- Added a responsive 8/4 media rail with paragraph anchoring, compact mobile floats, and image/video lightboxes.
- Added ordered page references and tag-scored similar-page lists to private previews and public pages.
- Added trusted local PHP, JavaScript, and CSS plugins with authenticated routes, dashboard widgets, declared browser assets, and persistent administrator controls.
- Hardened plugin development with non-executable discovery, authenticated boot, atomic failure isolation, namespace and asset boundaries, bounded request APIs, sanitized diagnostics, and expanded integration coverage.
- Added a disabled-by-default reference plugin and a complete development, compatibility, security, troubleshooting, and deployment guide.
- Added a signed-in dashboard and local collaborator accounts with inherited viewer/editor sharing for spaces and page subtrees.
- Introduced numbered database migrations, schema metadata in backups, and automatic pre-migration snapshots.

## 0.2.0 — 2026-07-13

- Refreshed the editor and public-site visual system.
- Added responsive off-canvas directory navigation with keyboard and reduced-motion support.
- Added a Tailscale-only deployment profile for n3.
- Added explicit trusted-proxy handling, secure-cookie detection, HSTS, request IDs, structured error logs, and Docker log rotation.
- Added host-mounted scheduled backups and production-aware backup/restore commands.
- Added the production deployment, update, backup, and recovery runbook.

## 0.1.0

- Established the schema and compatibility baseline for the first n3 release.
