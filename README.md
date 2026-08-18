# n3

n3 is a small self-hosted personal wiki for private, local use. It runs as one PHP/Apache container, stores data in SQLite, and uses plain browser JavaScript with no external runtime services.

This repository contains source code only. It intentionally excludes accounts, passwords, sessions, wiki content, uploads, backups, machine-specific addresses, and personal plugins.

## Requirements

- Docker Engine with Docker Compose v2
- A modern browser
- Optional: Tailscale for access from your own devices

## Quick start: this computer only

```bash
git clone YOUR_PRIVATE_REPOSITORY_URL n3
cd n3
cp .env.example .env
docker compose --profile backups up -d --build
```

Open <http://127.0.0.1:8786/setup>. Create the administrator account with a unique password of at least 12 characters. Setup closes after the first account is created.

Check the service:

```bash
curl http://127.0.0.1:8786/api/health
docker compose --profile backups ps
```

Stop or restart it with:

```bash
docker compose stop
docker compose start
```

Update it after pulling source changes:

```bash
git pull --ff-only
docker compose --profile backups up -d --build
```

## Private Tailscale access

Run `tailscale ip -4` on the n3 host, then copy `.env.tailscale.example` to `.env` and replace `YOUR_TAILSCALE_IP` with that device's address in both `APP_URL` and `APP_BIND_IP`.

```bash
cp .env.tailscale.example .env
docker compose --profile backups up -d --build
```

Open `http://YOUR_TAILSCALE_IP:8786/setup` from another device on the same tailnet. Do not forward port 8786 from your router. Keep `TRUSTED_PROXY_IPS` empty unless you deliberately add and configure a reverse proxy.

The interactive alternative is:

```bash
bin/n3-setup
```

It detects Tailscale when available and writes a private `.env` file for you.

## Configuration

`.env` is ignored by Git. Never commit it.

| Variable | Purpose | Safe local value |
| --- | --- | --- |
| `APP_NAME` | Name displayed in n3 | `n3` |
| `APP_TIMEZONE` | PHP timezone | `UTC` or your IANA timezone |
| `APP_URL` | Exact browser origin used for links | `http://127.0.0.1:8786` |
| `APP_BIND_IP` | Host interface that receives traffic | `127.0.0.1` or one Tailscale IP |
| `APP_PORT` | Host port | `8786` |
| `TRUSTED_PROXY_IPS` | Comma-separated proxies allowed to supply forwarding headers | Leave empty |
| `BACKUP_HOST_DIR` | Host backup directory | `./backups` |
| `BACKUP_RETENTION` | Number of scheduled archives retained | `14` |
| `BACKUP_INTERVAL_SECONDS` | Seconds between scheduled backups | `86400` |
| `COMPOSE_PROFILES` | Starts the backup worker without a CLI flag | `backups` |

`APP_URL` must be the address you actually use. Binding to `127.0.0.1` permits only the host computer. Binding to a specific Tailscale IP permits only that interface. Avoid `0.0.0.0` unless you intentionally want every host network interface to accept connections.

See [DEPLOYMENT.md](DEPLOYMENT.md) for operating and recovery notes.

## Data and backups

Runtime data lives in the Docker volume named `n3_data`; it is not part of this repository. This includes accounts, password hashes, sessions, pages, revisions, plugin settings, and uploaded media.

Create a validated backup:

```bash
bin/n3-backup
```

Archives are written to `./backups` by default. Restore one with:

```bash
bin/n3-restore backups/n3-YYYYMMDD-HHMMSS-ID.tar.gz
```

Backups contain private wiki content and password hashes. Keep them out of GitHub and store a second protected copy on another disk or encrypted device.

## Plugins

Plugins are intentionally kept in separate repositories. A plugin can be installed by copying its complete directory to `plugins/PLUGIN_ID` before the first start, or by packaging it as `PLUGIN_ID.zip` and uploading it from **Plugin management**.

Installed plugins execute as trusted same-origin code with application and database access. Review them before installation. The plugin contract is documented in [plugins/README.md](plugins/README.md).

## Main features

- Spaces, folders, rich-text pages, tags, search, favorites, and revision history
- Autosave with stale-write protection and local draft recovery
- Private-by-default pages with optional read-only publishing
- Local administrator and collaboration accounts with viewer/editor sharing
- Light, dark, and system themes plus local branding
- Uploaded images and video, page exports, profiles, and page provenance
- Soft deletion, restore, permanent deletion, and checked backup archives
- Trusted local plugins with migrations, routes, navigation, and private media
- Health and administrator diagnostics endpoints

## Security and intended use

n3 is intended for a trusted private host or tailnet. Authentication does not encrypt SQLite or backup archives. Tailscale encrypts tailnet traffic, but host storage and backups still need normal operating-system protection.

If you expose n3 beyond a private network, use a reviewed HTTPS reverse-proxy configuration and firewall policy. The default examples in this repository are deliberately local-only.

## Development checks

The test suites create and modify disposable application data. Never point them at your real n3 volume.

```bash
find public src scripts tests database examples -type f -name '*.php' -print0 | xargs -0 -n1 php -l
bash -n bin/n3-backup bin/n3-restore bin/n3-setup scripts/backup-loop.sh tests/host_backup_restore.sh
node --check public/assets/app.js
node --check public/assets/public.js
php tests/plugin_lifecycle.php
php tests/plugin_request_lifecycle.php
php tests/reference_plugin.php
docker compose config --quiet
```

Full disposable container smoke test:

```bash
docker compose down -v
docker compose up -d --build
docker compose exec -T n3 php /var/www/html/tests/auth_smoke.php
```

Browser tests:

```bash
npm ci
npx playwright install chromium
npm run test:e2e
```

## Stack

- PHP 8.3 and Apache
- SQLite through PDO
- Plain HTML, CSS, and JavaScript
- Docker Compose

The application version is stored in `VERSION` and returned by `/api/health`.
