# Database Foundation

N3 uses PDO with MariaDB. The database layer is deliberately smaller than an ORM: validated connection configuration, real prepared statements, explicit transactions, ordered/checksummed migrations, and repository interfaces.

See [DATABASE_SECURITY.md](DATABASE_SECURITY.md) for the credential model, threat boundaries, production gates, incident guidance, and automated security-control coverage.

## Supported direction

- Reference engine: MariaDB 11.8 LTS
- Compatibility target: MariaDB 12.3 LTS
- Character set: `utf8mb4`
- Table engine: InnoDB
- One isolated database per N3 installation
- PDO sessions explicitly use UTC for portable timestamp and lease comparisons

MariaDB 11.8.9 and 12.3.2 are verified through the Docker test environment. The host PHP installation does not need `pdo_mysql`; the optional `php-test` container provides it.

## Environment variables

| Variable | Required | Notes |
| --- | --- | --- |
| `DB_HOST` | No | Defaults to `127.0.0.1`; validated as an IP address or hostname. |
| `DB_PORT` | No | Defaults to `3306`; must be 1–65535. |
| `DB_NAME` | No | Defaults to `n3`; letters, numbers, and underscores only. |
| `DB_USER` | Yes | Runtime account; should have data privileges but no schema-changing privileges. |
| `DB_PASSWORD` | Yes | Must be supplied through the deployment environment and must not be logged or committed. |
| `DB_MIGRATION_USER` | Yes | Separate schema-change account used only by the migration CLI. |
| `DB_MIGRATION_PASSWORD` | Yes | Migration-account secret; must not be logged or committed. |

N3 does not load `.env` files. `.env.example` documents keys without being a secret source.

## Docker test environment

The local Docker environment is disposable test infrastructure, not a production deployment definition. It binds MariaDB only to `127.0.0.1:3307`, keeps data in a named volume, and creates separate runtime and migration accounts during first initialization. The PHP test container receives only test account credentials and mounts only the files needed by the suite; it cannot read the root password or `.env.docker`.

```bash
cp .env.docker.example .env.docker
# Replace all three example passwords before starting the service.
docker compose --env-file .env.docker up -d --wait mariadb
docker compose --env-file .env.docker --profile test build php-test
docker compose --env-file .env.docker --profile test run --rm php-test
docker compose --env-file .env.docker down
```

Changing initialization credentials does not modify an existing database volume. To intentionally reset this test database, first confirm no needed data exists, then run `docker compose --env-file .env.docker down --volumes` and start it again. That command permanently deletes the local N3 test volume.

## Migration policy

- Migration filenames and `version()` values must match.
- Files are applied in lexical order and recorded only after `up()` completes.
- SHA-256 checksums prevent silent editing of applied migrations.
- MariaDB DDL may implicitly commit. A failed multi-statement migration cannot be treated as transactionally rolled back; inspect and repair the schema before retrying.
- Keep migrations small and prefer one schema concern per migration.
- Rollback is destructive, disabled in production, and requires the explicit `--force` flag in non-production environments.
- Never use migration rollback as a substitute for a tested backup/restore procedure.

## Minimal user persistence

The `users` table stores identity and session-revocation data:

- numeric internal identifier;
- display name;
- original and normalized email;
- password hash;
- account status;
- server-assigned role key;
- verification, last-login, created, and updated timestamps;
- a session version used to invalidate previously issued sessions.

Separate tables contain hashed verification/reset tokens, fixed-window rate-limit buckets, and sanitized security events. Raw bearer tokens are never stored in MariaDB.

Phase 4 adds `pages` and `content_events`. Pages use a unique canonical slug, fixed draft/published status, author/editor foreign keys, and an incrementing lock version for optimistic concurrency. Content events store controlled lifecycle metadata rather than page bodies. See [CONTENT.md](CONTENT.md).

Phase 5B adds `modules`, `module_events`, `jobs`, and `job_events`. Module rows retain the last deployment-synchronized version, state, and manifest hash. Job payloads are bounded JSON; leases use random tokens, failures use controlled codes, and lifecycle events exclude payloads and exception text. See [MODULES.md](MODULES.md) and [JOBS.md](JOBS.md).

Phase 5C adds `webhook_receipts`. It stores only a controlled source key, SHA-256 delivery-ID hash, receipt time, and expiry. A composite primary key makes replay consumption atomic; expired receipts are pruned by bounded maintenance. Webhook bodies, signatures, secrets, URLs, and raw delivery IDs are not stored. See [WEBHOOKS.md](WEBHOOKS.md).

Phase 5D adds `module_migrations`. It records the owning module ID, lexical migration version, exact source-file checksum, and application time. Definitions run only through migration credentials, in module dependency order, under a database-scoped advisory lock. Applied history is retained when modules are disabled and cannot be dropped by the Core rollback command after records exist. See [MODULES.md](MODULES.md).

Phase 6A's optional Analytics module owns a prefixed `hourly_metrics` table. Its composite key contains only a UTC hour, controlled route category, method, and status. Counts and duration totals/maxima are atomically aggregated; there are no raw request, visitor, account, session, network, or browser fields. See [ANALYTICS.md](ANALYTICS.md).

Phase 6B changes no Analytics schema. Its administrator report uses one time-bounded query grouped by route category and reads completed hourly buckets only. The dashboard measures connection-plus-query duration transiently; it does not persist database telemetry or query text.

Phase 7A's optional Media module owns prefixed asset, upload-limit, and event tables. Asset rows catalog only the random identifier, administrator label, sanitized dimensions, processed byte size, master checksum, and timestamp. Fixed-window rate subjects are HMAC-hashed before storage. Media tables do not store raw uploads, original filenames, client MIME claims, source paths, metadata, raw IP addresses, request payloads, or image bytes. See [MEDIA.md](MEDIA.md).

Phase 7B adds a Media-owned one-row-per-Page attachment table with foreign keys to Core Pages and sanitized Media assets. It stores only Page ID, random asset ID, required alt text, and timestamps. Attachment mutations lock the draft Page version and write controlled content events in the same transaction. The public authorization query joins attachments to published Pages by indexed keys; public URLs, cache state, source paths, labels, and image bytes are not persisted.

Phase 8 adds one `site_settings` singleton, ordered `site_navigation_items` Page references, and sanitized `site_events`. Settings and navigation updates use an optimistic lock and one transaction. Scaffold installation requires an existing active, verified administrator and is idempotent by unique Page slug, singleton ID, and unique navigation Page reference. See [SITE.md](SITE.md).

Phase 9's optional Blog module owns deterministic prefixed `posts` and `events` tables. Posts use a unique binary-collated canonical slug, fixed draft/published lifecycle, author/editor foreign keys, publication-state checks, and optimistic lock version. Event rows use controlled lifecycle fields and omit post content, slugs, profiles, payloads, network identifiers, and tokens. See [BLOG.md](BLOG.md).

Public registration must never accept `account_status` or `role_key` from the request. `PdoUserRepository::createPending()` fixes these values to `pending_verification` and `member`.

## Database accounts

Production should use separate credentials:

1. `DB_MIGRATION_USER` may create and alter schema objects. Store it only in the controlled deployment environment.
2. The runtime account should have only the data privileges required by N3 and no schema-changing privileges.
3. Backup credentials and artifacts must be separately protected and kept outside the public document root.

The Docker initialization script implements these grants for local tests only. Production account provisioning remains a deployment responsibility.

## Validation status

Unit tests validate configuration and migration definitions without a database. Live integration tests require all of:

- PHP `pdo_mysql`;
- a disposable MariaDB database whose name ends in `_test`;
- `N3_TEST_DB_HOST`, `N3_TEST_DB_PORT`, and `N3_TEST_DB_NAME`;
- runtime `N3_TEST_DB_USER` and `N3_TEST_DB_PASSWORD` credentials;
- schema-changing `N3_TEST_DB_MIGRATION_USER` and `N3_TEST_DB_MIGRATION_PASSWORD` credentials.

Integration tests must never target staging or production data.

Observed after Phase 8 on 2026-09-02 with PHP 8.5.9: MariaDB 11.8.9 passed 216 tests and 917 assertions with one expected driver-presence skip; MariaDB 12.3.2 passed 216 tests and 871 assertions with seven version-conditioned skips. Coverage includes fresh/repeated/partial scaffold installation, forced transaction rollback, settings/navigation persistence, audit events, public filtering, and runtime schema-privilege denial.
