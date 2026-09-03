# Shared-Hosting Browser Installation

![Status implemented](https://img.shields.io/badge/status-implemented-2EA44F)
![HTTPS required in production](https://img.shields.io/badge/production-HTTPS_required-D73A49)

Phase 10B provides a one-time, neutral browser workflow for a fresh database. It does not write `.env`, create a database, grant database privileges, install optional code, or scaffold default Pages.

## Before opening the installer

1. Point the web document root at `public/`; never expose the repository root, `storage/`, `config/`, or `.env` files.
2. Take a backup if the selected database is not genuinely empty.
3. Configure the variables in `.env.example` through the hosting environment. N3 does not parse `.env` files itself.
4. Replace `SECURITY_HASH_KEY` with at least 32 random bytes and keep it permanently. It is application key material, not the installer password.
5. Set a different random `INSTALL_TOKEN` of at least 32 bytes. Do not reuse the security key.
6. Configure distinct `DB_USER` and `DB_MIGRATION_USER` accounts. Runtime needs application DML only; migration needs the reviewed DDL grants documented in `DATABASE_SECURITY.md`.
7. Choose `DB_TABLE_PREFIX` once. Leave it empty or use 2–24 lowercase ASCII characters beginning with a letter and ending in `_`.
8. Ensure PHP 8.5+, PDO MySQL, mbstring, HTTPS in production, and writable private `storage/` directories. Enabled Media also needs fileinfo and GD.

Generate independent secrets locally when OpenSSL is available:

```bash
openssl rand -hex 32
openssl rand -hex 32
```

Do not paste either generated value into logs, support tickets, screenshots, or this repository.

## Browser workflow

1. Visit `/install` over HTTPS.
2. Enter `INSTALL_TOKEN`. It is accepted by POST, captured as private session authorization, and removed by the redirect to `/install`.
3. Review the read-only application URL, database endpoint/name/users, table prefix, extensions, HTTPS, and private-storage results. Passwords and long-lived keys are never shown.
4. Run the reviewed Core migrations, enabled-module migrations, and module lifecycle synchronization. MariaDB DDL can commit implicitly, so do not interrupt the request deliberately.
5. Create the single administrator with name, email, and a unique passphrase of 12–1024 characters.
6. Completion writes MariaDB installation state and `storage/install/installed.lock`, clears installer authorization, and redirects to `/login`. It does not log the administrator in.
7. Remove `INSTALL_TOKEN` from the hosting environment. Keep `INSTALL_REOPEN=false` or unset.

The public `/` remains the neutral `Hello, world` view until an administrator explicitly runs `php bin/n3 site:scaffold --admin-email=ADDRESS`.

## Recovery and reopen

Migration history is recorded after each reviewed migration. If setup stops during DDL, restore from the verified backup when integrity is uncertain; otherwise revisit `/install`, authorize again, inspect the preflight, and retry. Applied checksums are verified and completed migrations are not repeated.

If administrator creation succeeded but final lock creation failed, the installer shows **Finish interrupted setup** and never offers a second administrator form. Finishing records completion and recreates the private lock.

After completion `/install` returns the normal application `404`. For read-only diagnostics only, configure a new temporary `INSTALL_TOKEN` and `INSTALL_REOPEN=true`, visit `/install`, then immediately remove the token and disable reopen mode. Reopen mode cannot migrate, reset data, or create another administrator.

The filesystem lock and database must be restored together. If the lock is missing but MariaDB says installation is complete, the runtime connection recreates it. If storage permissions prevent that repair, correct the private directory permissions before serving traffic.
