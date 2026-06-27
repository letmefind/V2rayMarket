#!/usr/bin/env bash
# یک‌بار: رسیدها و storage را قبل از recreate به volume دائمی منتقل می‌کند.
#
#   ./deploy/bin/migrate-instance-storage-volume.sh robot.bypax.store
#   ./deploy/bin/migrate-instance-storage-volume.sh --all-bots
#   ./deploy/bin/migrate-instance-storage-volume.sh --all-bots --no-recreate   # فقط mount.yml + بکاپ
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=lib/instance-compose.sh
source "$ROOT/deploy/bin/lib/instance-compose.sh"
INSTANCES_DIR="$ROOT/deploy/instances"
BACKUP_ROOT="${STORAGE_BACKUP_ROOT:-/root/storage-backups}"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

DO_RECREATE=1
TARGETS=()

while [ $# -gt 0 ]; do
  case "$1" in
    --all-bots) TARGETS=(--all-bots) ; shift ;;
    --no-recreate) DO_RECREATE=0 ; shift ;;
    -h|--help)
      sed -n '1,8p' "$0" | sed 's/^# \?//'
      exit 0
      ;;
    -*)
      err "آرگومنت ناشناس: $1"
      ;;
    *)
      TARGETS+=("$1")
      shift
      ;;
  esac
done

[ ${#TARGETS[@]} -gt 0 ] || err "دامنه یا --all-bots بدهید"

instance_is_pickup_only() {
  grep -qE '^APP_SHARE_PICKUP_ONLY=true' "$1/.env" 2>/dev/null
}

resolve_dest() {
  local name="$1"
  if [ -d "$name/.env" ]; then
    printf '%s\n' "$(cd "$name" && pwd)"
    return 0
  fi
  local d="$INSTANCES_DIR/$name"
  [ -f "$d/.env" ] || return 1
  printf '%s\n' "$(cd "$d" && pwd)"
}

list_bot_dests() {
  local d base
  for d in "$INSTANCES_DIR"/*/; do
    [ -d "$d" ] || continue
    base="$(basename "${d%/}")"
    case "$base" in _template*) continue ;; esac
    [ -f "$d/.env" ] || continue
    instance_is_pickup_only "$d" && continue
    printf '%s\n' "$(cd "$d" && pwd)"
  done
}

collect_dests() {
  DESTS=()
  if [ "${TARGETS[0]:-}" = "--all-bots" ]; then
    mapfile -t DESTS < <(list_bot_dests)
  else
    local t resolved
    for t in "${TARGETS[@]}"; do
      resolved="$(resolve_dest "$t")" || err "نمونه پیدا نشد: $t"
      DESTS+=("$resolved")
    done
  fi
  [ ${#DESTS[@]} -gt 0 ] || err "هیچ نمونه‌ای پیدا نشد"
}

compose_files_for() {
  local dest="$1"
  local pickup=0
  instance_is_pickup_only "$dest" && pickup=1
  COMPOSE_FILES=(
    -f "$ROOT/docker-compose.yml"
    -f "$ROOT/deploy/docker-compose.no-local-db.yml"
    -f "$ROOT/deploy/docker-compose.instance-env.yml"
    -f "$ROOT/deploy/docker-compose.build-root.yml"
    -f "$ROOT/deploy/docker-compose.web-rebuild.yml"
    -f "$ROOT/docker-compose.traefik.yml"
    -f "$dest/docker-compose.yml"
    -f "$dest/docker-compose.mount.yml"
  )
  if [ "$pickup" = 1 ]; then
    COMPOSE_FILES+=(-f "$ROOT/deploy/docker-compose.pickup-only.yml")
  else
    COMPOSE_FILES+=(-f "$ROOT/deploy/docker-compose.bot-workers.yml")
  fi
}

migrate_one() {
  local dest="$1"
  local envf="$dest/.env"
  [ -f "$envf" ] || err "نیست: $envf"

  set -a
  # shellcheck disable=SC1090
  source "$envf"
  set +a

  local project="${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME در .env}"
  local domain
  domain="$(basename "$dest")"
  local web_ctr="${project}-web-1"
  local backup_dir="${BACKUP_ROOT}/${project}"
  local env_abs
  env_abs="$(cd "$dest" && pwd)/.env"
  local mode=bot
  instance_is_pickup_only "$dest" && mode=pickup

  info "[$domain] بکاپ storage از $web_ctr …"
  mkdir -p "$backup_dir"
  if docker ps --format '{{.Names}}' | grep -qx "$web_ctr"; then
    docker cp "${web_ctr}:/var/www/html/storage/." "$backup_dir/" 2>/dev/null \
      || warn "[$domain] بکاپ storage خالی یا ناموفق — ادامه"
  else
    warn "[$domain] کانتینر web بالا نیست — فقط mount.yml به‌روز می‌شود"
  fi

  write_instance_mount_fragment "$dest" "$env_abs" "$mode"
  ok "[$domain] docker-compose.mount.yml با app_storage به‌روز شد"

  if [ "$DO_RECREATE" != 1 ]; then
    return 0
  fi

  export INSTANCE_ENV_FILE="$env_abs"
  export ENV_FILE="$INSTANCE_ENV_FILE"
  compose_files_for "$dest"

  info "[$domain] recreate web/queue/scheduler با volume دائمی …"
  docker compose --project-directory "$ROOT" \
    "${COMPOSE_FILES[@]}" \
    --env-file "$INSTANCE_ENV_FILE" \
    -p "$project" \
    up -d --force-recreate web queue scheduler 2>/dev/null \
    || docker compose --project-directory "$ROOT" \
      "${COMPOSE_FILES[@]}" \
      --env-file "$INSTANCE_ENV_FILE" \
      -p "$project" \
      up -d --force-recreate web

  sleep 8
  if [ -d "$backup_dir" ] && [ "$(ls -A "$backup_dir" 2>/dev/null | wc -l)" -gt 0 ]; then
    info "[$domain] بازگردانی storage به volume …"
    docker cp "$backup_dir/." "${web_ctr}:/var/www/html/storage/"
    docker exec "$web_ctr" chown -R www-data:www-data /var/www/html/storage 2>/dev/null || true
  fi

  local mounts orders receipts
  mounts="$(docker inspect "$web_ctr" --format '{{range .Mounts}}{{.Type}} {{if .Name}}{{.Name}}{{else}}bind{{end}} -> {{.Destination}}{{"\n"}}{{end}}' 2>/dev/null || true)"
  orders="$(docker exec "$web_ctr" php artisan tinker --execute="echo \\App\\Models\\Order::count();" 2>/dev/null | tail -1 || echo "?")"
  receipts="$(docker exec "$web_ctr" sh -c 'find storage/app/public/receipts -type f 2>/dev/null | wc -l' 2>/dev/null | tr -d " \n" || echo "?")"

  echo "$mounts" | grep -q 'app_storage' && ok "[$domain] app_storage mount OK — orders=$orders receipts=$receipts" \
    || warn "[$domain] app_storage mount دیده نشد — mounts:\n$mounts"
}

cd "$ROOT"
collect_dests

for dest in "${DESTS[@]}"; do
  migrate_one "$dest"
done

ok "تمام — ${#DESTS[@]} نمونه"
