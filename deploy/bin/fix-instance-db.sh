#!/usr/bin/env bash
# اصلاح .env نمونه + image + Traefik vars + recreate + migrate
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CLUSTER="$ROOT/deploy/.provision/cluster.env"
DOMAIN="${1:-bale.cyou}"
DEST="$ROOT/deploy/instances/$DOMAIN"
PROJECT="vpnmarket_${DOMAIN//./_}"
CONTAINER="${PROJECT}-web-1"

err() { echo "✗ $*" >&2; exit 1; }

[ -f "$CLUSTER" ] || err "نیست: $CLUSTER"
if [ -d "$DEST/.env" ]; then
  err "$DEST/.env یک پوشه است! rm -rf $DEST/.env"
fi
[ -f "$DEST/.env" ] || err "نیست: $DEST/.env"

# shellcheck disable=SC1090
source "$CLUSTER"
[ -n "${DB_PASSWORD:-}" ] || err "DB_PASSWORD در cluster.env خالی است"

pick_app_image() {
  if docker image inspect vpnmarket/app:latest >/dev/null 2>&1; then
    echo "vpnmarket/app:latest"
  elif docker image inspect vpnmarket-local:latest >/dev/null 2>&1; then
    echo "vpnmarket-local:latest"
  elif [ -n "${APP_IMAGE:-}" ] && docker image inspect "${APP_IMAGE}" >/dev/null 2>&1; then
    echo "$APP_IMAGE"
  else
    err "هیچ image محلی نیست — اول: docker build -t vpnmarket/app:latest -f Dockerfile ."
  fi
}

set_env_var() {
  local file="$1" key="$2" val="$3"
  if grep -q "^${key}=" "$file" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$file"
  else
    echo "${key}=${val}" >>"$file"
  fi
}

APP_IMAGE="$(pick_app_image)"

echo "→ قبل:"
grep -E '^(DB_|APP_IMAGE|APP_DOMAIN|TRAEFIK_ROUTER_NAME)=' "$DEST/.env" 2>/dev/null || true

echo "→ همگام‌سازی DB و متغیرهای compose"
grep -v -E '^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|MYSQL_ROOT_PASSWORD|APP_IMAGE|APP_DOMAIN|TRAEFIK_ROUTER_NAME|COMPOSE_PROJECT_NAME)=' "$DEST/.env" >"${DEST}/.env.tmp"
{
  echo "COMPOSE_PROJECT_NAME=${PROJECT}"
  echo "APP_IMAGE=${APP_IMAGE}"
  echo "APP_DOMAIN=${DOMAIN}"
  echo "TRAEFIK_ROUTER_NAME=${PROJECT}"
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

echo "→ بعد:"
grep -E '^(DB_|APP_IMAGE|APP_DOMAIN|TRAEFIK_ROUTER_NAME)=' "$DEST/.env"

echo "→ MySQL user ${DB_USERNAME}"
docker exec vpnmarket_shared_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e \
  "ALTER USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}'; FLUSH PRIVILEGES;"

export INSTANCE_ENV_FILE="$(cd "$DEST" && pwd)/.env"
export ENV_FILE="$INSTANCE_ENV_FILE"
set -a
# shellcheck disable=SC1090
source "$INSTANCE_ENV_FILE"
set +a

echo "→ recreate container (image: ${APP_IMAGE})"
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

sleep 10
docker exec "$CONTAINER" rm -f /var/www/html/bootstrap/cache/config.php 2>/dev/null || true
docker exec "$CONTAINER" php artisan config:clear --no-interaction

echo "→ .env داخل کانتینر:"
docker exec "$CONTAINER" grep -E '^(DB_|APP_DOMAIN)=' /var/www/html/.env

echo "→ migrate"
docker exec "$CONTAINER" php artisan migrate --force --no-interaction

echo "✓ تمام"
