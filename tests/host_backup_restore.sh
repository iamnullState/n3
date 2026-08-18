#!/usr/bin/env bash
set -euo pipefail

root_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
work_dir="$(mktemp -d)"
project="n3_host_test_${RANDOM}_$$"
override="$work_dir/compose.test.yaml"
backup_dir="$work_dir/backups"

cleanup() {
  COMPOSE_PROJECT_NAME="$project" COMPOSE_FILE="$root_dir/compose.yaml:$override" docker compose down -v --rmi local --remove-orphans >/dev/null 2>&1 || true
  rm -rf "$work_dir"
}
trap cleanup EXIT

mkdir -p "$backup_dir"

{
  printf '%s\n' 'services:'
  printf '%s\n' '  n3:'
  printf '%s\n' '    ports: !reset []'
  printf '%s\n' '    environment:'
  printf '%s\n' '      APP_URL: http://127.0.0.1'
} > "$override"

export COMPOSE_PROJECT_NAME="$project"
export COMPOSE_FILE="$root_dir/compose.yaml:$override"

docker compose up -d --build >/dev/null
until docker compose exec -T n3 php -r "exit(@file_get_contents('http://127.0.0.1/api/health') === false ? 1 : 0);"; do
  sleep 1
done

docker compose exec -T n3 php /var/www/html/tests/auth_smoke.php >/dev/null

counts_before="$(docker compose exec -T n3 php -r '
  $pdo = new PDO("sqlite:/var/www/data/n3.sqlite");
  foreach (["users", "spaces", "pages", "page_revisions"] as $table) {
      echo $table, "=", $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(), "\n";
  }
')"

"$root_dir/bin/n3-backup" "$backup_dir" >/dev/null
archive="$(find "$backup_dir" -maxdepth 1 -type f -name 'n3-*.tar.gz' -print -quit)"
[[ -n "$archive" && -s "$archive" ]]
echo '✓ host backup command creates a non-empty archive'

docker compose exec -T n3 php -r '
  $pdo = new PDO("sqlite:/var/www/data/n3.sqlite");
  $pdo->exec("DELETE FROM page_revisions; DELETE FROM pages; DELETE FROM spaces;");
'

mutated_pages="$(docker compose exec -T n3 php -r '$pdo = new PDO("sqlite:/var/www/data/n3.sqlite"); echo $pdo->query("SELECT COUNT(*) FROM pages")->fetchColumn();')"
[[ "$mutated_pages" == '0' ]]
echo '✓ disposable database is deliberately changed before restore'

"$root_dir/bin/n3-restore" "$archive" >/dev/null

counts_after="$(docker compose exec -T n3 php -r '
  $pdo = new PDO("sqlite:/var/www/data/n3.sqlite");
  foreach (["users", "spaces", "pages", "page_revisions"] as $table) {
      echo $table, "=", $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn(), "\n";
  }
')"

[[ "$counts_after" == "$counts_before" ]]
echo '✓ host restore command recovers all recorded counts'

docker compose exec -T n3 php -r '
  $pdo = new PDO("sqlite:/var/www/data/n3.sqlite");
  if ($pdo->query("PRAGMA integrity_check")->fetchColumn() !== "ok") exit(1);
  if ($pdo->query("PRAGMA foreign_key_check")->fetchAll()) exit(1);
  if ((int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1 AND password_hash != \"\"")->fetchColumn() !== 1) exit(1);
'
echo '✓ restored database passes integrity, foreign-key, and owner checks'

health="$(docker compose exec -T n3 php -r 'echo file_get_contents("http://127.0.0.1/api/health");')"
expected_version="$(tr -d '[:space:]' < "$root_dir/VERSION")"
[[ "$health" == *'"status":"ok"'* && "$health" == *"\"version\":\"${expected_version}\""* ]]
echo '✓ restored service restarts healthy on the current version'

echo
echo 'n3 host backup and restore test passed.'
