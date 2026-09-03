# Shared-Hosting Deployment Baseline

![Phase 11A implemented](https://img.shields.io/badge/Phase_11A-implemented-2EA44F)
![Apache or LiteSpeed](https://img.shields.io/badge/web-Apache_%7C_LiteSpeed-6F42C1)
![Production rehearsal required](https://img.shields.io/badge/production-rehearsal_required-D73A49)

Phase 11A defines N3's first production deployment baseline for Apache 2.4-compatible and LiteSpeed shared hosting. It is a secure procedure and automated configuration preflight, not certification of an untested provider. Phase 11B adds encrypted coordinated backup and clean-target restore commands. Production mail, monitoring, scheduled tasks, and a full provider rehearsal remain later gates.

## Hosting contract

- Set the site document root to the release's `public/` directory. The repository-root `.htaccess` denies access as a fail-closed safety net; it is not permission to serve the repository root.
- The host must honor `.htaccess`, `Options -Indexes`, `mod_rewrite` rules, and authorization directives. `mod_headers` is recommended for static asset headers.
- Use PHP 8.5 or newer with PDO, PDO MySQL, and mbstring. Enable fileinfo and GD with JPEG, PNG, and WebP when Media is enabled.
- Terminate HTTPS at a server whose direct PHP request supplies `HTTPS=on` or `SERVER_PORT=443`. Forwarded headers are intentionally untrusted. Production HTTPS responses send HSTS for the current host without `includeSubDomains` or preload.
- Use MariaDB 11.8 or 12.3 on `localhost`, `127.0.0.1`, or `::1`. Remote database service is not approved until certificate-verified TLS settings exist.
- Keep `storage/` outside `public/`, writable by the PHP account, inaccessible to other system users, and free of symlinks at security-sensitive paths. Prefer `0700` directories and `0600` files where the host permits them; never solve access failures with `0777`.

## Production environment

Set and validate these values in the hosting control panel or process environment:

```text
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
SECURITY_HASH_KEY=<at least 32 random bytes; permanent>
EMAIL_VERIFICATION_REQUIRED=true
REGISTRATION_ENABLED=false
INSTALL_REOPEN=false
DB_HOST=localhost
```

The current production notifier is not an external mail adapter, so registration must remain disabled. Never commit secrets or place them in web-accessible files.

`INSTALL_TOKEN` and `DB_MIGRATION_USER`/`DB_MIGRATION_PASSWORD` are temporary installation or upgrade inputs. Remove them from the normal web process after the operation. Production web bootstrap fails closed while any of those values remain available.

## Release preparation

Build dependencies for a production release from the committed lockfile:

```bash
composer install --no-dev --classmap-authoritative --no-interaction
composer validate --no-check-publish
find bootstrap config public src -name '*.php' -print0 | xargs -0 -n1 php -l
```

Do not upload development dependencies, `.env`, `.git/`, tests, Docker files, database exports, logs, sessions, outbox messages, or local caches into the public document root. Preserve private `storage/` across code releases.

## Fresh installation

1. Create distinct runtime, migration, and read-only backup database accounts using the grants in `DATABASE_SECURITY.md`.
2. Upload the reviewed release, install production Composer dependencies, set permissions, and point the document root at `public/`.
3. Configure the production environment plus a temporary independent `INSTALL_TOKEN` and migration credentials.
4. Visit `/install` over HTTPS, review preflight, apply migrations, and create the first administrator.
5. Remove `INSTALL_TOKEN` and both `DB_MIGRATION_*` values from the web environment. Keep `INSTALL_REOPEN=false`.
6. Temporarily inject migration credentials into a private CLI process and run `php bin/n3 production:check`. Do not put passwords directly in shell history.
7. Remove the CLI migration credentials, confirm `/install` is `404`, then smoke-test `/`, `/login`, and an authenticated administrator route.

## Upgrade procedure

1. Put the site into the hosting provider's maintenance/offline mode and create, verify, and copy off-host a Phase 11B encrypted bundle as documented in `BACKUPS.md`.
2. Upload the new release without replacing private `storage/` or production secrets.
3. Run the optimized Composer install above.
4. Temporarily inject migration credentials into the CLI environment. Review and apply in order:

```bash
php bin/n3 migrate:status
php bin/n3 migrate
php bin/n3 module:migrate:status
php bin/n3 module:migrate
php bin/n3 module:migrate --apply
php bin/n3 module:status
php bin/n3 module:sync
php bin/n3 module:sync --apply
php bin/n3 production:check
```

5. Remove migration credentials from the CLI and web environments before serving traffic.
6. Smoke-test the public, authentication, and enabled administrator/module routes; inspect the hosting error log and `storage/logs/app.log`; then end maintenance mode.

`production:check` is read-only. It validates production flags, secret removal rules, local database placement, private storage/lock permissions, distinct database users, both database connections, installation completion, Apache protection files, migration state, module lifecycle state, and enabled Media extensions. During this CLI check only, migration credentials are expected and temporarily allowed.

## Recovery boundary

Core and module migrations are forward-only after identity or content exists. Do not roll application code back across a schema change unless that release is explicitly compatible with the migrated schema. On an uncertain or interrupted migration, keep traffic offline and choose either a reviewed forward repair or restoration of the entire verified backup. Never delete migration history or installation state to force a retry.

## Remaining production gates

- Verify this baseline on the selected shared host and record its PHP handler, `.htaccess` behavior, HTTPS variables, permissions, limits, and logs.
- Rehearse Phase 11B backup/restore on the selected provider and record recovery time plus acceptance evidence.
- Select a production mail adapter before enabling registration or recovery delivery.
- Define scheduled pruning/job execution, monitoring, alerting, and incident ownership.
- Rehearse a complete staging install, upgrade, failure, and recovery before live traffic.
