#!/usr/bin/env bash
# ساخت یا تعمیر .env نمونه + recreate + migrate
# Usage: fix-instance-db.sh <domain> [pickup|bot]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=lib/instance-compose.sh
source "$ROOT/deploy/bin/lib/instance-compose.sh"
CLUSTER="$ROOT/deploy/.provision/cluster.env"
DOMAIN="${1:-bale.cyou}"
INSTANCE_TYPE="${2:-pickup}"
DEST="$ROOT/deploy/instances/$DOMAIN"

domain_slug() {
  echo "$1" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g' | sed 's/__*/_/g' | sed 's/^_//;s/_$//'
}

normalize_domain() {
  echo "$1" | tr '[:upper:]' '[:lower:]'
}

err() { echo "✗ $*" >&2; exit 1; }
info() { echo "→ $*"; }

[ -f "$CLUSTER" ] || err "نیست: $CLUSTER — اول ./deploy/install.sh یا گزینه ۴ provision"
# shellcheck disable=SC1090
source "$CLUSTER"
[ -n "${DB_PASSWORD:-}" ] || err "DB_PASSWORD در cluster.env خالی است"

if [ -d "$DEST/.env" ]; then
  err "$DEST/.env یک پوشه است — حذف: rm -rf $DEST/.env"
fi

mkdir -p "$DEST"
PROJECT="vpnmarket_$(domain_slug "$DOMAIN")"
DOMAIN_LC="$(normalize_domain "$DOMAIN")"
CONTAINER="${PROJECT}-web-1"

pick_app_image() {
  if docker image inspect vpnmarket/app:latest >/dev/null 2>&1; then
    echo "vpnmarket/app:latest"
  elif docker image inspect vpnmarket-local:latest >/dev/null 2>&1; then
    echo "vpnmarket-local:latest"
  else
    err "image نیست — docker build -t vpnmarket/app:latest -f Dockerfile ."
  fi
}

APP_IMAGE="$(pick_app_image)"

ensure_env_mount_vars() {
  local envf="$1"
  local abs
  abs="$(cd "$(dirname "$envf")" && pwd)/$(basename "$envf")"
  grep -v -E '^(ENV_FILE|INSTANCE_ENV_FILE)=' "$envf" >"${envf}.tmp"
  mv "${envf}.tmp" "$envf"
  {
    echo "ENV_FILE=${abs}"
    echo "INSTANCE_ENV_FILE=${abs}"
  } >>"$envf"
}

if [ "$INSTANCE_TYPE" = "bot" ]; then
  cp "$ROOT/deploy/instances/_template/docker-compose.yml" "$DEST/docker-compose.yml"
else
  cp "$ROOT/deploy/instances/_template.pickup/docker-compose.yml" "$DEST/docker-compose.yml"
  INSTANCE_TYPE=pickup
fi

if [ ! -f "$DEST/.env" ]; then
  info "ساخت .env جدید ($INSTANCE_TYPE) برای $DOMAIN_LC"
  APP_KEY="base64:$(openssl rand -base64 32)"
  if [ "$INSTANCE_TYPE" = "bot" ]; then
    cat >"$DEST/.env" <<EOF
COMPOSE_PROJECT_NAME=${PROJECT}
APP_IMAGE=${APP_IMAGE}
APP_INSTANCE_ID=$(domain_slug "$DOMAIN")
APP_NAME="VPNMarket"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMAIN_LC}
APP_SHARE_PICKUP_ONLY=false
APP_DOMAIN=${DOMAIN_LC}
TRAEFIK_ROUTER_NAME=${PROJECT}
TRAEFIK_CERT_RESOLVER=${TRAEFIK_CERT_RESOLVER:-letsencrypt}
TRAEFIK_NETWORK=${TRAEFIK_NETWORK:-proxy}
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
SHARED_DATA_NETWORK=${SHARED_DATA_NETWORK:-vpnmarket_shared_data}
REDIS_HOST=redis
REDIS_PASSWORD=null
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=database
IRAN_SERVICE_SHARE_URL=${IRAN_SERVICE_SHARE_URL:-https://bale.cyou}
RUN_MIGRATIONS=true
WAIT_FOR_DB=true
EOF
  else
    cat >"$DEST/.env" <<EOF
COMPOSE_PROJECT_NAME=${PROJECT}
APP_IMAGE=${APP_IMAGE}
APP_INSTANCE_ID=pickup
APP_NAME="دریافت اشتراک"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMAIN_LC}
APP_SHARE_PICKUP_ONLY=true
APP_DOMAIN=${DOMAIN_LC}
TRAEFIK_ROUTER_NAME=${PROJECT}
TRAEFIK_CERT_RESOLVER=${TRAEFIK_CERT_RESOLVER:-letsencrypt}
TRAEFIK_NETWORK=${TRAEFIK_NETWORK:-proxy}
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=${DB_USERNAME}
DB_PASSWORD=${DB_PASSWORD}
MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}
SHARED_DATA_NETWORK=${SHARED_DATA_NETWORK:-vpnmarket_shared_data}
REDIS_HOST=redis
QUEUE_CONNECTION=sync
CACHE_STORE=file
SESSION_DRIVER=file
RUN_MIGRATIONS=true
WAIT_FOR_DB=true
EOF
  fi
  chmod 640 "$DEST/.env"
else
  info "تعمیر DB_* در .env موجود"
  grep -v -E '^(DB_CONNECTION|DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|MYSQL_ROOT_PASSWORD|APP_IMAGE|APP_DOMAIN|TRAEFIK_ROUTER_NAME|COMPOSE_PROJECT_NAME)=' "$DEST/.env" >"${DEST}/.env.tmp"
  {
    echo "COMPOSE_PROJECT_NAME=${PROJECT}"
    echo "APP_IMAGE=${APP_IMAGE}"
    echo "APP_DOMAIN=${DOMAIN_LC}"
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
fi

ensure_env_mount_vars "$DEST/.env"
ENV_ABS="$(cd "$DEST" && pwd)/.env"
write_instance_mount_fragment "$DEST" "$ENV_ABS" "$INSTANCE_TYPE" || err "نوشتن docker-compose.mount.yml ناموفق"

echo "→ .env:"
grep -E '^(APP_KEY|DB_|APP_DOMAIN|COMPOSE_PROJECT_NAME|ENV_FILE)=' "$DEST/.env"

if docker ps --format '{{.Names}}' 2>/dev/null | grep -qx vpnmarket_shared_mysql; then
  info "همگام‌سازی کاربر MySQL"
  docker exec vpnmarket_shared_mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -e \
    "ALTER USER '${DB_USERNAME}'@'%' IDENTIFIED BY '${DB_PASSWORD}'; FLUSH PRIVILEGES;" 2>/dev/null || true
fi

export INSTANCE_ENV_FILE="$(cd "$DEST" && pwd)/.env"
export ENV_FILE="$INSTANCE_ENV_FILE"
set -a
# shellcheck disable=SC1090
source "$INSTANCE_ENV_FILE"
set +a

COMPOSE_FILES=(
  -f "$ROOT/docker-compose.yml"
  -f "$ROOT/deploy/docker-compose.no-local-db.yml"
  -f "$ROOT/deploy/docker-compose.instance-env.yml"
  -f "$ROOT/deploy/docker-compose.build-root.yml"
)
if [ "$INSTANCE_TYPE" = "pickup" ]; then
  COMPOSE_FILES+=(-f "$ROOT/deploy/docker-compose.pickup-only.yml")
else
  COMPOSE_FILES+=(-f "$ROOT/deploy/docker-compose.bot-workers.yml")
fi
COMPOSE_FILES+=(
  -f "$ROOT/docker-compose.traefik.yml"
  -f "$DEST/docker-compose.yml"
  -f "$DEST/docker-compose.mount.yml"
)

info "recreate container ($CONTAINER)"
docker compose --project-directory "$ROOT" \
  "${COMPOSE_FILES[@]}" \
  --env-file "$INSTANCE_ENV_FILE" \
  -p "$PROJECT" \
  up -d --force-recreate

sleep 12
for i in 1 2 3 4 5 6 7 8 9 10; do
  if docker exec "$CONTAINER" test -f /run/instance.env 2>/dev/null; then
    break
  fi
  sleep 2
done

docker exec "$CONTAINER" rm -f /var/www/html/bootstrap/cache/config.php 2>/dev/null || true
docker exec "$CONTAINER" php artisan config:clear --no-interaction 2>/dev/null || true

info "mount:"
docker inspect "$CONTAINER" --format '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}}{{"\n"}}{{end}}' 2>/dev/null || true

info "داخل کانتینر:"
CTR_STATE="$(docker inspect -f '{{.State.Status}}' "$CONTAINER" 2>/dev/null || echo missing)"
if [ "$CTR_STATE" != "running" ]; then
  docker logs "$CONTAINER" --tail 40 2>&1 || true
  err "کانتینر $CONTAINER در حالت $CTR_STATE است — لاگ بالا"
fi
if ! docker exec "$CONTAINER" test -f /run/instance.env 2>/dev/null; then
  echo "  ENV_FILE در .env: $(grep -E '^ENV_FILE=' "$DEST/.env" || echo '(خالی)')"
  echo "  mount در compose.yml: $(grep run/instance.env "$DEST/docker-compose.yml" || true)"
  docker inspect "$CONTAINER" --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{"\n"}}{{end}}' 2>/dev/null || true
  err "mount /run/instance.env نیست — docker logs $CONTAINER"
fi
docker exec "$CONTAINER" head -3 /run/instance.env
docker exec "$CONTAINER" grep -E '^(DB_USERNAME|DB_DATABASE)=' /var/www/html/.env

info "migrate"
docker exec "$CONTAINER" php artisan migrate --force --no-interaction

echo "✓ تمام — https://${DOMAIN_LC}/up"
