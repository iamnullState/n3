#!/bin/sh
set -eu

interval="${BACKUP_INTERVAL_SECONDS:-86400}"
while true; do
  if ! php /var/www/html/scripts/backup.php "${BACKUP_DIR:-/var/www/data/backups}"; then
    echo "Backup will be retried after the configured interval." >&2
  fi
  sleep "$interval"
done
