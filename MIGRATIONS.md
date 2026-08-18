# n3 Database Migration Policy

This policy governs schema and persistent-data changes beginning with n3 `0.1.0`. Its purpose is to make upgrades predictable, preserve owner data, and give every release a tested recovery path.

## Current schema

Version `0.1.0` is the pre-ledger schema baseline. The bootstrap retains an idempotent baseline initializer for those databases, then runs immutable numbered migrations from `database/migrations/` and records them in `schema_migrations`. The current development tree supports numbered schema version `4`.

- Creates the core tables and indexes when absent
- Detects and adds columns introduced during early development
- Backfills stable page slugs and initial revision records
- Seeds example content only when there are no spaces

Migration `001_add_extensions_and_collaboration.php`, first shipped by n3 `0.3.0`, introduced page references, resource sharing, space ownership, administrator metadata, the migration ledger, and automatic owner-only pre-migration snapshots. Migration `002_add_plugin_enablement_overrides.php`, also first shipped by `0.3.0`, added database-backed plugin enablement overrides. Migration `003_add_profiles_and_authorship.php`, intended for `0.4.0`, adds private-by-default profile metadata, stable profile slugs, page author/last-editor references, and first-publication timestamps. Migration `004_add_application_settings.php` adds whitelabel branding, network metadata, and light/dark theme-token storage. Baseline version `0` is recorded before numbered migrations run. New schema changes must begin with `005` and must not be added to the baseline initializer.

## Migration ledger

Migrations are ordered, immutable files under `database/migrations/` using a zero-padded numeric prefix and descriptive name. The next additions would be:

```text
database/migrations/
  005_add_page_summary.php
  006_add_page_status.php
  007_add_another_change.php
```

The runner bootstraps the `schema_migrations` ledger before discovering numbered files. The database must contain these fields:

| Field | Purpose |
| --- | --- |
| `version` | Unique monotonically increasing integer matching the filename prefix |
| `name` | Human-readable migration name |
| `app_version` | n3 version that first shipped the migration |
| `applied_at` | UTC timestamp recorded after successful application |

Migration files are append-only after release. Never edit, reorder, or reuse a released migration number. Correct a released migration with a new migration.

## Upgrade procedure

On application startup, before serving database-backed requests, the migration runner must:

1. Open SQLite with foreign keys enabled and the configured busy timeout.
2. Validate that the database is readable and determine its recorded schema version.
3. Reject a database whose schema version is newer than the running application supports.
4. Discover pending migrations and verify that their numeric sequence has no duplicates or gaps.
5. Create a consistent owner-only pre-migration snapshot before applying the first pending migration.
6. Acquire a SQLite write lock with `BEGIN IMMEDIATE` and apply each migration in order.
7. Record a migration in `schema_migrations` only within the same successful transaction as its schema/data changes.
8. Run `PRAGMA foreign_key_check` before commit and abort on any violation.
9. Commit, then allow normal request dispatch.

Migrations must be safe to execute exactly once. The ledger, not repeated schema probing, determines whether a numbered migration has run.

## Migration design rules

- Prefer small migrations with one coherent purpose.
- Include data backfills in the migration that requires them.
- Make backfills deterministic and independent of request/session state.
- Never depend on network access, browser state, or external services.
- Preserve IDs, timestamps, public slugs, revision history, tags, and hierarchy unless the migration explicitly changes their contract.
- Use SQLite's table-rebuild procedure when changing or removing constrained columns.
- Keep foreign keys enabled and validate them before commit.
- Do not seed example content during an upgrade.
- Avoid long-running transformations in a web request when they can be prepared and tested offline.
- Never log page content, password hashes, session data, or backup secrets.

For destructive changes, use an expand/contract sequence across releases when practical:

1. Add the replacement structure while retaining the old structure.
2. Deploy code that can read existing data and write the new representation.
3. Backfill and validate the new representation.
4. Remove the obsolete structure in a later release after rollback compatibility is no longer required.

## Failure behavior

If a migration fails:

- Roll back its transaction; do not write its ledger row.
- Stop application startup for database-backed routes rather than operating on a partially upgraded schema.
- Return a generic unavailable response to browsers and write a concise error to container logs.
- Preserve the pre-migration snapshot and original database.
- Never automatically retry a migration in a tight request loop.

Operators should inspect the error, retain a copy of the failed database, and either deploy a corrected forward migration or restore the pre-migration snapshot.

## Rollback and application downgrade

n3 does not use automatic down migrations. Reverse SQL is often lossy and can create a false sense of safety after columns or data have changed.

Rollback means restoring a validated database snapshot:

1. Stop writes by stopping the n3 service.
2. Preserve the failed/current database for diagnosis.
3. Restore the pre-migration snapshot or a validated `bin/n3-backup` archive.
4. Start the application version compatible with that snapshot.
5. Verify `/api/health`, SQLite integrity, record counts, login, and a representative private/public page.

An older application must refuse to open a database with a newer ledger version unless that release is explicitly documented as schema-compatible. Rolling back application code without rolling back an incompatible database is unsupported.

Pre-migration snapshots must:

- Be consistent SQLite snapshots, not raw copies of a live WAL database
- Use owner-only permissions
- Be restored with the application release that created them; pre-ledger snapshots do not contain numbered schema metadata
- Be retained separately from the active database until the upgrade is verified
- Follow the same privacy handling as normal backups

## Backup format compatibility

Application schema version and backup archive format version are separate concerns:

- `schema_migrations.version` describes the SQLite schema.
- `manifest.json.version` describes the archive container format.
- `VERSION` describes the n3 application release.

Backup manifests record both the source application version and numbered schema version. Restore validates the archive and rejects a snapshot created by a newer unsupported schema before import; after import, application startup runs any supported forward migrations.

## Required tests for every schema change

Every migration change must add automated coverage for:

- A clean installation directly to the latest schema
- Upgrade from the immediately previous released schema
- Reopening an already migrated database without changes
- Failure rollback with no ledger row or partial data change
- Foreign-key and SQLite integrity checks
- Backup before upgrade and restore after upgrade
- Rejection by an older/incompatible application
- Preservation of representative private/public pages, folders, tags, revisions, users, and slugs

Fixtures must contain meaningful relationships and non-ASCII content, not only empty tables.

## Release checklist for schema changes

- [ ] Assign the next migration number and do not modify released files.
- [ ] Record the shipping application version in the migration ledger.
- [ ] Document data transformations and expected runtime/disk usage.
- [ ] Run the complete migration test matrix.
- [ ] Create and validate a backup before production upgrade.
- [ ] Verify record counts and representative workflows after upgrade.
- [ ] Keep the pre-migration snapshot until the release is accepted.
- [ ] Document the compatible application downgrade boundary.
