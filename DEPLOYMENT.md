# Private local deployment

n3 is designed to run on one trusted machine and remain reachable only from that machine or a private Tailscale network.

## Choose one listener

Host-only access:

```env
APP_URL=http://127.0.0.1:8786
APP_BIND_IP=127.0.0.1
APP_PORT=8786
TRUSTED_PROXY_IPS=
```

Tailscale-only access:

```env
APP_URL=http://YOUR_TAILSCALE_IP:8786
APP_BIND_IP=YOUR_TAILSCALE_IP
APP_PORT=8786
TRUSTED_PROXY_IPS=
```

Find the host's Tailscale IPv4 address with `tailscale ip -4`. Use that exact address instead of `YOUR_TAILSCALE_IP`. Do not use an address copied from another machine.

## Start

```bash
cp .env.example .env
# Edit .env before continuing.
docker compose --profile backups up -d --build
docker compose --profile backups ps
```

Open `APP_URL/setup` and create the administrator account. Check health with:

```bash
curl "$APP_URL/api/health"
```

If `APP_URL` is not exported in your shell, type the configured URL directly.

## Routine operation

```bash
docker compose --profile backups ps
docker compose logs --tail=100 n3
docker compose restart n3
docker compose --profile backups up -d --build
```

Keep Docker, the PHP base image, the host operating system, and Tailscale updated. Do not expose port 8786 through router port-forwarding.

## Backups

The scheduled worker writes archives to `BACKUP_HOST_DIR`. Create an on-demand archive with:

```bash
bin/n3-backup
```

Validate recovery periodically using a disposable installation. Restore only a trusted archive:

```bash
bin/n3-restore backups/n3-YYYYMMDD-HHMMSS-ID.tar.gz
```

Backup archives contain private content and password hashes. Keep them outside Git, restrict filesystem access, and maintain a protected second copy.

## Moving to another host

1. Create and validate a current backup.
2. Clone this source repository on the new host.
3. Create a new `.env` using the new host's loopback or Tailscale address.
4. Start n3 and restore the archive.
5. Verify `/api/health`, sign in, inspect system diagnostics, and test one new backup.

Never copy the old host's Tailscale address into the new host configuration.
