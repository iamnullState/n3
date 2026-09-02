# Blog Module

Phase 9 adds `n3/blog` `0.1.0` as the first optional content module. It is disabled by default and owns its content, lifecycle events, forward migration, services, routes, and views. Core and Page do not depend on it.

## Enable and deploy

Set `BLOG_ENABLED=true` in the reviewed application environment. Before applying its migration, take and verify a database backup. Use the standard preview-first deployment order:

```bash
php bin/n3 migrate
BLOG_ENABLED=true php bin/n3 module:migrate:status
BLOG_ENABLED=true php bin/n3 module:migrate
BLOG_ENABLED=true php bin/n3 module:migrate --apply
BLOG_ENABLED=true php bin/n3 module:sync
BLOG_ENABLED=true php bin/n3 module:sync --apply
BLOG_ENABLED=true php bin/n3 module:migrate:status
BLOG_ENABLED=true php bin/n3 module:status
```

Disabling the environment flag removes Blog services and routes from application bootstrap. It does not delete posts, events, or migration history. Destructive uninstall and backward migrations are unsupported.

## Content and lifecycle

A post contains a title, canonical ASCII slug, optional excerpt, plain-text body, fixed `draft` or `published` status, author/editor user IDs, creation/update/publication timestamps, and an optimistic lock version. Draft bodies may be blank; publishing requires nonblank content. Published posts must be unpublished before their content can be edited.

The module owns deterministic prefixed `posts` and `events` tables. Each successful create, edit, publish, or unpublish transaction records a controlled event with the post ID, actor ID, lifecycle states, optional request correlation ID, and timestamp. Events never contain post text, slugs, account profiles, request payloads, tokens, IP addresses, or user agents.

## Routes

Administrator routes require a current active, verified fixed `admin`:

- `GET /admin/blog`
- `GET /admin/blog/create`
- `POST /admin/blog`
- `GET /admin/blog/{id}/edit`
- `POST /admin/blog/{id}`
- `GET /admin/blog/{id}/preview`
- `POST /admin/blog/{id}/publish`
- `POST /admin/blog/{id}/unpublish`

All administrator mutations require session-bound, action-specific CSRF tokens. Private pages and redirects use `Cache-Control: no-store` and `X-Robots-Tag: noindex, nofollow`. Stale writes and state transitions are rejected through exact lock-version checks.

Public routes are:

- `GET /blog` with an optional scalar `page` query
- `GET /blog/{slug}`

The index returns ten published posts per page, accepts pages 1–1000, and returns `404` beyond the last available page. List queries omit post bodies. Detail retrieval requires the exact canonical slug and published state. Titles, excerpts, bodies, URLs, attributes, and administrator values are contextually escaped; bodies remain plain text with preserved line breaks.

## Security and privacy boundary

Blog receives a narrow Core `CurrentActorProvider` containing only the internal user ID and fixed authority. This is separate from Analytics' authority-only principal contract. Blog receives no email, display name, password data, session identifier, or token.

Runtime persistence uses prepared statements and the shared restricted DML account. Schema changes run only through the migration account. Unique canonical slugs, foreign keys, status/publication checks, controlled event checks, transactions, and optimistic updates reinforce application validation. Storage failures return controlled responses and log only an event key plus exception class.

## Accessibility and performance gates

Views use semantic headers, navigation, main/article/section landmarks, explicit labels, associated help/error text, status announcements, keyboard-operable native controls, descriptive link text, and responsive layouts. The shared CSS remains below the existing 20 KiB development budget; JavaScript is not required. Public list HTML is bounded by fixed pagination and list queries avoid loading bodies.

Automated coverage exercises enablement, migration ownership and runtime DDL denial, validation, lifecycle/audit transactions, CSRF, authorization, contextual escaping, stale writes, published-only retrieval, pagination bounds, response privacy, and safe storage failure on MariaDB 11.8 and 12.3.

## Recovery and deferrals

If module migration application fails, stop the rollout, inspect schema plus `module_migrations`, and create a reviewed forward repair. Do not retry blindly, delete retained history, alter checksums, or destructively roll back tables containing Blog data. Database backups must include both Blog tables and their referenced Identity users.

Categories, tags, comments, author profiles, search, feeds, scheduling, revisions, deletion, rich HTML/WYSIWYG, Blog media, and automatic main-navigation contribution remain deferred.
