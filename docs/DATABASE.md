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

Observed on 2026-08-30 against both MariaDB 11.8.9 and 12.3.2 with PHP 8.5.9: 59 tests, 184 assertions, 58 passed, and one environment-specific unit test was skipped because `pdo_mysql` was present.
