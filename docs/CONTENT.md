# Page Content Vertical Slice

![Status implemented](https://img.shields.io/badge/status-implemented-2EA44F)

Phase 4 introduces one deliberately narrow CMS content type: `Page`. It proves authenticated authoring, validation, preview, publication, public retrieval, auditability, and stale-write protection without introducing configurable schemas or rich HTML.

## Content model

| Field | Rule |
| --- | --- |
| Title | Required; 1–200 Unicode characters. |
| Slug | Required; 1–160 ASCII characters using lowercase letters, numbers, and single hyphens. Globally unique. |
| Excerpt | Optional; at most 500 Unicode characters. Used as the public meta description. |
| Body | Plain text; at most 100,000 Unicode characters. May be blank as a draft but is required for publication. |
| Status | Fixed to `draft` or `published`. |
| Attribution | Original author and most recent editor reference user IDs. |
| Lock version | Incremented on every edit and lifecycle change to reject stale writes. |
| Lead image | Optional when Media is enabled; one sanitized library image with required 2–300 character alternative text. |

Plain text is stored as entered and contextually escaped when rendered. Script- or HTML-shaped input is displayed as text and is never executed. Phase 7B permits one structural lead image, not arbitrary media markup in the body. The WYSIWYG reference, Markdown, trusted HTML, inline media embeds, galleries, and shortcode processing remain deferred until a sanitization and content-format contract is approved.

## Authority and lifecycle

Only active, verified `admin` accounts may access Page administration or preview routes. Authenticated `member` accounts receive `403`; anonymous requests redirect to login. Every mutation requires a session-bound CSRF token.

The supported lifecycle is:

```text
draft → published → draft
```

Published pages must be unpublished before editing. This prevents an edit from silently changing live content while revision support is unavailable. Unpublish is the current reversible recovery operation. Permanent deletion, trash, revision history, review/approval, scheduling, redirects, and rollback to an older body are deferred.

When Media is enabled, the same draft-only and optimistic-lock rule applies to attaching, replacing, changing alt text, or detaching the lead image. Each successful relation change increments the Page lock version and records `media_attached` or `media_detached`. Publishing authorizes the sanitized preview derivative for public origin delivery; unpublishing or detaching removes that authorization. Previously downloaded public copies cannot be revoked.

## Routes

- `GET /admin/pages` — administration list
- `GET /admin/pages/create` and `POST /admin/pages` — create a draft
- `GET /admin/pages/{id}/edit` and `POST /admin/pages/{id}` — edit a draft
- `GET /admin/pages/{id}/preview` — administrator-only preview with `noindex,nofollow`
- `POST /admin/pages/{id}/publish`
- `POST /admin/pages/{id}/unpublish`
- `POST /admin/pages/{id}/media` — attach, change, or detach one lead image while draft; available only when Media is enabled
- `GET /pages/{slug}` — published content only
- `GET /` — the published `home` Page after explicit Phase 8 scaffold installation; otherwise the neutral `Hello, world` fallback
- `GET /media/{id}.webp` — sanitized preview derivative only when attached to at least one published Page

Stale lock versions and attempts to edit a published page return a conflict instead of overwriting data. Unknown, draft, noncanonical, and invalid public slugs return `404`.

## Persistence and audit

Migration `202608300004_create_pages` adds `pages` and `content_events`. It does not alter identity data. Content audit rows record the page, actor, controlled event type, lifecycle transition, request ID, and timestamp. They do not duplicate titles, slugs, excerpts, or bodies.

Media remains optional and owns the `page_attachments` relationship through its `202609010003_create_page_attachments` forward migration. Core `pages` has no Media column or foreign key. Disabling Media leaves Page storage and routes operational but omits attachment UI/rendering and public derivative delivery.

Take a database backup before applying the migration. Do not destructively roll back the tables after real Page content exists. Forward repair or verified backup restoration must be chosen for production incidents.

## Initial quality gates

- Public retrieval uses one indexed Page query and no content-side JavaScript.
- An attached public Page adds one bounded attachment lookup and one same-origin, maximum-480-pixel WebP request.
- Administration lists at most 200 recently updated pages in this first slice; pagination is required before exceeding that operational bound.
- Public HTML remains useful with JavaScript disabled.
- Forms use explicit labels, associated errors, status text, semantic headings, visible focus, and keyboard-native controls.
- Validate responsive behavior at 1440, 1024, 768, and 390 CSS pixels and at 200% zoom.
- Validate hostile content escaping, authorization, CSRF, unique slugs, lifecycle transitions, stale versions, audit rows, and public visibility through automated tests.

Observed locally on 2026-08-30: the browser flow from login through publish and public retrieval passed; warm public responses were approximately 2 ms with 1.2 KB of HTML. These numbers are development evidence, not production service-level objectives.
