#!/usr/bin/env bash
# یک‌بار روی سرور: اصلاح DB .env نمونه + پاک کردن config cache + migrate
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CLUSTER="$ROOT/deploy/.provision/cluster.env"
DOMAIN="${1:-bale.cyou}"
DEST="$ROOT/deploy/instances/$DOMAIN"
PROJECT="vpnmarket_${DOMAIN//./_}"
CONTAINER="${PROJECT}-web-1"

err() { echo "✗ $*" >&2; exit 1; }

[ -f "$CLUSTER" ] || err "نیست: $CLUSTER — ابتدا ./provision گزینه ۴"
if [ -d "$DEST/.env" ]; then
  err "$DEST/.env یک پوشه است! حذف کنید: rm -rf $DEST/.env && دوباره این اسکریپت را بزنید"
fi
[ -f "$DEST/.env" ] || err "نیست: $DEST/.env"

# shellcheck disable=SC1090
source "$CLUSTER"
[ -n "${DB_PASSWORD:-}" ] || err "DB_PASSWORD در cluster.env خالی است"

echo "→ قبل (هاست):"
grep -E '^DB_(USERNAME|PASSWORD|DATABASE)=' "$DEST/.env" 2>/dev/null || true

echo "→ همگام‌سازی $DEST/.env"
grep -v -E '^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|MYSQL_ROOT_PASSWORD)=' "$DEST/.env" >"${DEST}/.env.tmp"
{
  echo 'DB_CONNECTION=mysql'
  echo 'DB_HOST=mysql'
  echo 'DB_PORT=3306'
  printf 'DB_DATABASE=%s\n' "$DB_DATABASE"
  printf 'DB_USERNAME=%s\n' "$DB_USERNAME"
  printf 'DB_PASSWORD=%s\n' "$DB_PASSWORD"
  printf 'MYSQL_ROOT_PASSWORD=%s\n' "$MYSQL_ROOT_PASSWORD"
} >>"${DEST}/.env.tmp"
mv "${DEST}/.env.tmp" "$DEST/.env"
chmod 640 "$DEST/.env"

echo "→ MySQL user ${DB_USERNAME}"
docker exec vpnmarket_shared_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e \
  "ALTER USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}'; FLUSH PRIVILEGES;"

export INSTANCE_ENV_FILE="$(cd "$DEST" && pwd)/.env"
export ENV_FILE="$INSTANCE_ENV_FILE"

echo "→ recreate container"
docker compose --project-directory "$ROOT" \
  -f "$ROOT/docker-compose.yml" \
  -f "$ROOT/deploy/docker-compose.no-local-db.yml" \
  -f "$ROOT/deploy/docker-compose.instance-env.yml" \
  -f "$ROOT/deploy/docker-compose.build-root.yml" \
  -f "$ROOT/deploy/docker-compose.pickup-only.yml" \
  -f "$ROOT/docker-compose.traefik.yml" \
  -f "$DEST/docker-compose.yml" \
  --env-file "$INSTANCE_ENV_FILE" \
  -p "$PROJECT" \
  up -d --force-recreate

sleep 8
docker exec "$CONTAINER" rm -f /var/www/html/bootstrap/cache/config.php 2>/dev/null || true
docker exec "$CONTAINER" php artisan config:clear --no-interaction

echo "→ .env داخل کانتینر:"
docker exec "$CONTAINER" grep -E '^DB_(USERNAME|PASSWORD|DATABASE)=' /var/www/html/.env

echo "→ migrate"
docker exec "$CONTAINER" php artisan migrate --force --no-interaction

echo "✓ تمام"
