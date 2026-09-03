# Encrypted Backup and Clean-Target Recovery

![Phase 11B implemented](https://img.shields.io/badge/Phase_11B-implemented-2EA44F)
![Encrypted XChaCha20](https://img.shields.io/badge/encryption-XChaCha20--Poly1305-6F42C1)
![Restore clean target only](https://img.shields.io/badge/restore-clean_target_only-D73A49)

Phase 11B provides private CLI-only backup, verification, retention, and restore operations for one N3 installation. A backup is not considered recoverable until its encrypted bundle verifies and a clean-target restoration has passed application checks.

## Bundle contract

Each backup is a private directory named with a UTC timestamp and random suffix. It contains:

- a streaming-encrypted MariaDB logical dump of existing managed N3 tables only;
- individually streaming-encrypted durable private files;
- a versioned manifest with source version, database identity, immutable table prefix, table/file inventory, byte counts, and SHA-256 digests;
- an HMAC-SHA-256 manifest authenticator.

Encryption uses sodium XChaCha20-Poly1305 secret streams with domain-separated encryption and manifest-authentication keys derived from the master key, plus a distinct authenticated context for every artifact. Plain database dumps are streamed directly from `mariadb-dump` into encryption and are never intentionally written to disk. Database passwords are passed through short-lived `0600` client option files, not command arguments.

Durable storage currently includes `install/installed.lock`, `modules/`, `files/`, `images/`, and `videos/`. Sessions, installer sessions, logs, local outbox messages, general caches, and backup bundles are excluded. Restored users must authenticate again.

## Required private CLI environment

Backup creation requires dedicated read-only `DB_BACKUP_USER` and `DB_BACKUP_PASSWORD` credentials with `SELECT` on the installation database. Restore uses the separately controlled `DB_MIGRATION_*` account because it creates tables and inserts data. Neither credential set belongs in the normal web-process environment.

Configure:

```text
BACKUP_PATH=/absolute/private/path/to/n3-backups
BACKUP_ENCRYPTION_KEY=<base64 encoding of exactly 32 random bytes>
BACKUP_RETENTION_DAYS=30
MARIADB_DUMP_BINARY=mariadb-dump
MARIADB_CLIENT_BINARY=mariadb
```

Generate the encryption key on a trusted machine:

```bash
php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'
```

Store that key in a separate secrets system and recovery record. Do not place it inside, beside, or only on the same host as the bundles. Losing the key makes every bundle unreadable; exposing it compromises their confidentiality and authenticity.

## Create and verify

Place the site into maintenance/offline mode so MariaDB and private files represent one coordinated point. Then run:

```bash
php bin/n3 backup:create --maintenance-confirmed
php bin/n3 backup:verify --id=BACKUP_ID
```

The create command refuses incomplete installations, a missing private installation lock, missing managed tables, symlinked durable files, concurrent backup operations, database-client failures, and unsafe backup destinations. It writes into a temporary private directory and publishes the bundle only by final rename.

Copy the complete verified bundle to encrypted off-host storage. Keep at least one copy outside the hosting account and verify that copy by setting `BACKUP_PATH` to its private parent and running `backup:verify` again.

## Retention

Retention is preview-first and considers only correctly named, authenticated, fully verified bundles. Unknown files and corrupt bundles are preserved for investigation.

```bash
php bin/n3 backup:prune
php bin/n3 backup:prune --days=30
php bin/n3 backup:prune --days=30 --apply
```

Confirm an independent off-host copy before `--apply`. Deletion is irreversible. A practical starting policy is daily backups retained for 30 days plus separately managed longer-term monthly copies; adjust only after legal, privacy, storage, and recovery needs are known.

## Clean-target restore rehearsal

Restore never overwrites a live database or durable private file. Prepare a clean release directory, an empty target database using the same `DB_TABLE_PREFIX`, and a private storage root with none of the durable directories above. Configure the target database plus temporary `DB_MIGRATION_*`, `BACKUP_PATH`, and `BACKUP_ENCRYPTION_KEY`, then preview:

```bash
php bin/n3 backup:restore --id=BACKUP_ID --storage-target=/absolute/private/clean-storage
```

After confirming the target is isolated and maintenance is active:

```bash
php bin/n3 backup:restore --id=BACKUP_ID \
  --storage-target=/absolute/private/clean-storage \
  --apply --maintenance-confirmed --clean-target-confirmed
```

The command authenticates and decrypt-verifies the entire bundle before checking the target. It refuses any existing managed N3 table, table-prefix mismatch, public/symlink storage target, or existing durable target data. After import it verifies the exact managed-table inventory plus coordinated completed installation state and lock.

If restore fails after database import begins, discard the isolated target database and start again from a newly empty target. Never retry against partially imported tables and never delete migration history to force progress.

## Recovery acceptance check

Before promoting the restored target:

1. Run `php bin/n3 production:check` with temporary migration credentials.
2. Remove backup keys, backup credentials, and migration credentials from the web environment.
3. Confirm the restored installation lock is `0600` and private storage is outside `public/`.
4. Smoke-test `/`, `/login`, administrator access, Pages, Blog when enabled, and Media delivery when enabled.
5. Compare expected administrator/content counts and inspect `storage/logs/app.log` for controlled errors.
6. Record the bundle ID, restoration duration, checks performed, result, and operator without recording secrets.

Only after those checks should DNS, document-root, or database configuration be switched to the restored target. Phase 11B does not automate that provider-specific cutover.
