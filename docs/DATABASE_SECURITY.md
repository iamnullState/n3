# Database Security

This document defines the security boundary for N3's PDO/MariaDB foundation. It covers the current development and test implementation and records the controls that a future production deployment must supply. It is not a production-readiness claim.

## Security objectives

- Keep database administration credentials out of the web runtime.
- Prevent the runtime account from changing schema or MariaDB account privileges.
- Treat all request-derived values as data, never as SQL structure.
- Fail without exposing credentials, DSNs, SQL parameters, or personal data.
- Make schema changes ordered, reviewable, checksum-protected, and recoverable.
- Keep each N3 installation isolated in its own database and credential set.

## Trust boundaries and protected data

| Boundary or asset | Primary risk | Required control |
| --- | --- | --- |
| Public request to repository | SQL injection and privilege assignment | Validate at the use-case boundary; use native prepared statements; assign roles and status server-side. |
| PHP runtime to MariaDB | Credential theft or network interception | Use a dedicated runtime account, private networking, and verified TLS in non-local environments. |
| Migration process to MariaDB | Destructive or unauthorized schema changes | Use a separate migration account only during controlled releases; require reviewed migrations and backups. |
| User identity rows | Account takeover and privacy exposure | Store password hashes only; restrict access; minimize logs and exports containing email addresses. |
| Database backups | Offline disclosure or unrecoverable loss | Encrypt backups, restrict backup credentials, define retention, and test restoration. |
| Migration history | Tampering or drift | Restrict production access, retain checksums, and investigate any mismatch instead of overwriting history. |
| Module registry | Unreviewed code activation or state drift | Keep configuration as the reviewed allowlist; preview/apply synchronization; reject downgrades and same-version manifest changes. |
| Durable jobs | Duplicate side effects, payload disclosure, stale workers | Require idempotent handlers, bounded JSON, random leases, token-checked completion, sanitized errors, and explicit recovery. |
| Webhook receipts | Signed-request replay or secret/body disclosure | Store only source-scoped delivery hashes, atomically reject duplicates, retain through the replay window, and never persist signatures, secrets, or bodies. |
| Module migrations | Unreviewed DDL, history tampering, partial schema changes | Require trusted file-backed definitions, migration-only credentials, checksums, dependency ordering, advisory locking, explicit apply, backups, and forward repair. |

## Credential roles

| Account | Intended access | Prohibited use |
| --- | --- | --- |
| Root/bootstrap | Local container initialization or controlled database administration only | Web requests, application runtime, automated tests, or routine migrations. |
| Migration | Required DDL plus migration-table DML during an approved release | Web runtime, public request handling, account administration, or `GRANT`/`CREATE USER`. |
| Runtime | Application DML needed by repositories | DDL, MariaDB user administration, system privilege tables, backup administration, or granting access. |
| Backup | Future read/backup operations only | Application queries, schema migrations, or restore operations unless separately approved. |

Every installation must use distinct credentials. Staging, test, and production credentials must never be shared. A module must use Core repositories or an explicitly reviewed service contract; it must not introduce independent root or migration credentials.

### Current Docker grants

The local bootstrap script grants the runtime account `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the disposable test schema. It grants the migration account the same DML plus `CREATE`, `ALTER`, `DROP`, `INDEX`, and `REFERENCES`. Neither account receives global privileges, account-management privileges, or `GRANT OPTION`.

Because application tables are created after container initialization, the local runtime grant currently applies schema-wide and therefore includes the `schema_migrations` table. This is an accepted local-test limitation, not the production target. Production provisioning must apply table-specific runtime grants after migrations, keep migration history inaccessible to the web runtime, and update grants deliberately when a module adds tables.

## Network and transport controls

The Docker service binds only to `127.0.0.1:3307`; it is not reachable through a wildcard host interface. The Compose network is for disposable local testing.

A future staging or production environment must:

- keep MariaDB on a private network with firewall rules limited to approved application and administration hosts;
- avoid publishing port `3306` to the public internet;
- configure encrypted MariaDB transport and certificate verification;
- reject plaintext remote database connections;
- document connection limits, timeouts, and resource protections;
- keep administration access separate from the application path.

`ConnectionFactory` does not yet expose TLS certificate options, so remote production database connectivity is not approved.

## Secret handling and rotation

- `.env.docker` is ignored by Git and is for disposable local credentials only.
- Example passwords are rejected by the initialization script; root, runtime, and migration passwords must be distinct.
- The PHP test container receives only runtime and migration test credentials. Its mounts exclude `.env.docker`, and it does not receive the root password.
- Production secrets must come from the selected platform's secret manager or protected service environment, not a committed file, image layer, public document root, or command history.
- Passwords, DSNs containing credentials, access tokens, and password hashes must not be logged.

Credential rotation procedure for a future deployment:

1. Confirm the target installation and take a tested backup when the change could affect availability.
2. Create or update the replacement account through the controlled administration path.
3. Apply the minimum grants and verify connection/TLS behavior from the intended service.
4. Update the protected runtime secret and restart or reload dependent services.
5. confirm application and migration smoke checks;
6. revoke the old credential and verify it can no longer connect;
7. record the rotation without recording the secret.

## Query and repository controls

- PDO uses exception mode, associative fetches, native prepared statements, and non-stringified fetches.
- Database names are restricted to letters, numbers, and underscores before they enter a DSN.
- Host, port, username length, and non-empty credentials are validated.
- `PdoUserRepository` binds every stored value as a parameter.
- Public input must never select SQL identifiers, migration names, account status, or role keys.
- `createPending()` assigns `pending_verification` and `member` in trusted server code.
- Connection failures are wrapped in a generic `DatabaseException`; user-facing paths must not expose the underlying PDO exception.

Prepared statements do not replace input validation. Identity use cases enforce email normalization and bounds, a 12–1024 character passphrase policy, server-assigned authority, database-backed rate limits, CSRF, and generic account-discovery responses.

## Migration and recovery controls

- Migration filenames and declared versions must match.
- Applied files are verified with SHA-256 checksums; changed or missing applied migrations stop execution.
- Rollback requires an explicit non-production `--force` flag and is disabled in production.
- MariaDB DDL may commit implicitly, so a partially failed migration requires inspection and an explicit repair plan.
- Migration credentials must not be available to the web worker.
- A schema rollback is not a backup strategy.

Before any production migration, require a current backup, a tested restore procedure, a reviewed forward migration, a compatibility assessment, an outage expectation, and a documented recovery decision. Backup and restore automation is not implemented yet.

## Logging and incident response

Safe database logs may include a request identifier, operation category, migration version, duration, affected-row count, and a stable error category. Do not log passwords, password hashes, full DSNs, bound values, raw registration payloads, or complete PDO exception traces in public responses.

For a suspected credential or data exposure:

1. restrict access without destroying logs or database evidence;
2. identify the affected installation, account, time window, and accessible data;
3. rotate the exposed credential and terminate obsolete sessions where supported;
4. inspect MariaDB and application logs for misuse;
5. validate schema and migration-history integrity;
6. restore only from a verified clean backup when integrity is uncertain;
7. document impact, notification obligations, root cause, and preventive changes.

## Automated security coverage

The PHPUnit suite verifies:

- DSN construction excludes passwords and rejects identifier injection;
- invalid hosts, ports, database names, usernames, and empty passwords fail closed;
- runtime and migration credentials load separately;
- connection failures expose only a controlled message;
- transactions commit, roll back, preserve the original exception, and reject nesting;
- migrations create the expected constraints and enforce normalized-email uniqueness;
- SQL-looking email values remain data when passed through repository methods;
- the runtime account cannot create, alter, drop, or index schema objects;
- the runtime account cannot read MariaDB privilege tables;
- account status and role are fixed by trusted repository code.

Run host unit tests and live MariaDB tests with:

```bash
composer test
docker compose --env-file .env.docker --profile test run --rm php-test
```

## Open production-security gates

- Select the production runtime, network, secret manager, and MariaDB topology.
- Add verified TLS configuration to `ConnectionFactory`.
- Provision table-specific runtime grants after migrations and deny runtime access to migration history.
- Design and test encrypted backup, restore, retention, and deletion procedures.
- Define database audit logging, monitoring, alert thresholds, and incident ownership.
- Define credential rotation automation and emergency account revocation.
- Define data retention, export, deletion, and privacy requirements for user records.
- Complete a deployment-specific threat model and security review.

Identity security events store only controlled event/outcome codes, keyed subject/IP hashes, optional user IDs, request IDs, and timestamps. Verification and reset bearer tokens are stored only as SHA-256 hashes in MariaDB. Password reset increments the user's session version so existing sessions fail validation. The private local outbox necessarily contains one-time delivery URLs and is prohibited for enabled production registration.

Page authoring is restricted to active verified administrators and all mutations require CSRF. Public queries select only published rows by an indexed, validated canonical slug. Page bodies remain plain text and are escaped at render time. Optimistic lock versions reject stale updates, and content audit events exclude page text. Published content must be unpublished before editing until a revision model is implemented.

Module synchronization uses the runtime DML account only after the migration account creates the lifecycle tables. It never installs PHP code or executes DDL. Job payloads are private application data and must not contain secrets; job audit rows store controlled codes only. Lease tokens are random, compared in conditional updates, never printed by CLI commands, and cleared at transition. Production grants should eventually restrict modules through Core service contracts, but trusted in-process PHP cannot be database-sandboxed from other Core code under the current shared runtime account.

Webhook replay receipts use a source-scoped composite primary key so concurrent duplicate deliveries cannot both succeed. Only a SHA-256 delivery-ID hash is stored. Receipt pruning must preserve the complete accepted replay window. API bearer and idempotency persistence are contracts only in Phase 5C: no credential issuance or business route is enabled, and plaintext bearer tokens must never be stored when that work begins.

Module migrations are trusted deployment code but execute only through `DB_MIGRATION_USER`. The runtime account can read migration status for deployment drift checks but cannot execute module DDL. Source files are checksummed, pending migrations on an existing module require a forward manifest version, and applied history is retained. Because MariaDB DDL may commit implicitly, partial failure requires schema inspection and a reviewed forward repair; automatic rollback and manual checksum/history edits are prohibited.

Phase 6A Analytics uses the shared runtime DML account for one atomic hourly-bucket upsert per request only when explicitly enabled. Core passes a controlled route category rather than a raw path; the table has no visitor, account, session, request, IP, user-agent, referrer, query, slug, cookie, or payload fields. Analytics failures are sanitized and fail-soft, so monitoring must detect lost metrics without making public request availability depend on reporting. Aggregate traffic data and CLI output remain private operational data and use a default 90-day retention target.

Phase 6B reporting requires an active administrator session and receives only fixed authority from Core's lazy principal provider; Analytics receives no account ID or profile. Report periods and grouping are allowlisted, responses are `no-store`/`noindex`, and failures expose neither SQL nor exception messages. The report connection uses the runtime DML account and performs no DDL. Dashboard output remains sensitive operational data even though it contains no visitor-level records.
