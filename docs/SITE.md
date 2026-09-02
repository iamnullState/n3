# Site Scaffold and White-label Settings

Phase 8 turns a migrated N3 installation into a usable default site without introducing a second content model. The scaffold creates five ordinary `Page` records and stores mutable site identity and navigation in MariaDB.

## Installation

Take and verify a database backup, apply Core migrations, and bootstrap the first active, verified administrator. Then run:

```bash
php bin/n3 site:scaffold --admin-email=admin@example.test
```

The email must identify the existing active, verified `admin`. Passwords are never accepted by this command. It creates published `home`, `about`, `contact`, `privacy-policy`, and `terms` Pages only when each slug is absent, creates the singleton settings row only when absent, and adds missing navigation entries. Re-running it is safe and does not duplicate or overwrite Pages, settings, or navigation.

The seeded policy text is a placeholder, not legal advice. Review it before production use. The Contact Page does not submit or send mail.

## Site behavior

- `/` renders the published Page whose slug is `home`. If it is absent or unpublished, N3 uses the static Core landing page.
- `/pages/{slug}` continues to render every other published Page.
- `GET /site.css` publishes only the validated primary color as a small cacheable stylesheet.
- `GET /admin/site` lets an active administrator edit identity and the stored Page navigation; `POST /admin/site` requires session CSRF and an optimistic lock version.
- Public navigation includes only entries marked visible whose referenced Page is currently published.

Home and all other seeded Pages remain ordinary CMS records. Administrators may edit, publish, or unpublish them through the existing Page workflow. Phase 8 intentionally adds no protected-page exception.

## Validation and data boundaries

Site name is 2–100 Unicode characters, tagline is at most 200, contact email is normalized and validated, and primary color must be an uppercase-normalized six-digit hex value with at least 4.5:1 contrast against white. An optional logo path must be a same-origin SVG, PNG, JPEG, or WebP under `/assets/photos/` or `/assets/svg/`; remote URLs, other extensions, and traversal are rejected. The deployment owner remains responsible for placing and backing up that referenced public asset.

Navigation is bounded to 200 unique Page references with unique positions from 1–65535 and labels from 1–80 Unicode characters. Submitted Page IDs are checked against MariaDB before replacement. HTML-shaped identity, Page, and navigation text is contextually escaped on output.

`site_events` records only `scaffold_installed` or `site_updated`, actor, optional request ID, and time. It never stores old/new settings, email copies, Page text, tokens, or request payloads.

## Backup and recovery

Back up `site_settings`, `site_navigation_items`, `site_events`, `pages`, `content_events`, users referenced by their foreign keys, and any public logo file as one coordinated installation state. Do not use destructive migration rollback after real settings or scaffold content exists.

If settings or navigation are damaged, prefer verified backup restoration or a reviewed forward repair. Re-running `site:scaffold` can add missing default Pages/navigation but deliberately does not reset existing values, so it is not a settings-reset command.

## Deferred

Multiple themes, a visual theme builder, logo upload, contact submission, outbound mail, custom content types, and module-system changes remain outside Phase 8.
