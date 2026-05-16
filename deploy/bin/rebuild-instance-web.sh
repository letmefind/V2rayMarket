#!/usr/bin/env bash
# rebuild کانتینر web یک یا همهٔ نمونه‌ها بعد از git pull (کد Filament / اپ داخل image)
#
#   ./deploy/bin/rebuild-instance-web.sh robot.bypax.store
#   ./deploy/bin/rebuild-instance-web.sh --all-bots          # همهٔ ربات‌ها (نه pickup)
#   ./deploy/bin/rebuild-instance-web.sh --all             # ربات + pickup
#   ./deploy/bin/rebuild-instance-web.sh aof.bypax.store bypassnet.bypax.store
#
#   --no-build     فقط recreate (بعد از یک بار build برای همه)
#   --no-clear     artisan optimize:clear اجرا نشود
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTANCES_DIR="$ROOT/deploy/instances"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

DO_BUILD=1
DO_CLEAR=1
TARGETS=()

while [ $# -gt 0 ]; do
  case "$1" in
    --all-bots) TARGETS=(--all-bots) ; shift ;;
    --all) TARGETS=(--all) ; shift ;;
    --no-build) DO_BUILD=0 ; shift ;;
    --no-clear) DO_CLEAR=0 ; shift ;;
    -h|--help)
      sed -n '1,12p' "$0" | sed 's/^# \?//'
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
  local dest="$1"
  grep -qE '^APP_SHARE_PICKUP_ONLY=true' "$dest/.env" 2>/dev/null
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

list_all_dests() {
  local d base
  for d in "$INSTANCES_DIR"/*/; do
    [ -d "$d" ] || continue
    base="$(basename "${d%/}")"
    case "$base" in _template*) continue ;; esac
    [ -f "$d/.env" ] || continue
    printf '%s\n' "$(cd "$d" && pwd)"
  done
}

compose_up_web() {
  local dest="$1"
  local pickup=0
  instance_is_pickup_only "$dest" && pickup=1

  local envf="$dest/.env"
  [ -f "$envf" ] || err "نیست: $envf"

  export INSTANCE_ENV_FILE="$(cd "$dest" && pwd)/.env"
  export ENV_FILE="$INSTANCE_ENV_FILE"
  set -a
  # shellcheck disable=SC1090
  source "$INSTANCE_ENV_FILE"
  set +a

  local project="${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME در .env}"
  local -a files=(
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
    files+=(-f "$ROOT/deploy/docker-compose.pickup-only.yml")
  else
    files+=(-f "$ROOT/deploy/docker-compose.bot-workers.yml")
  fi

  local -a up_args=(up -d --force-recreate)
  [ "$DO_BUILD" = 1 ] && up_args+=(--build)
  up_args+=(web)

  info "rebuild web: $(basename "$dest") ($project)"
  docker compose --project-directory "$ROOT" \
    "${files[@]}" \
    --env-file "$INSTANCE_ENV_FILE" \
    -p "$project" \
    "${up_args[@]}"

  if [ "$DO_CLEAR" = 1 ]; then
    local ctr="${project}-web-1"
    if docker ps --format '{{.Names}}' | grep -qx "$ctr"; then
      docker exec -u www-data "$ctr" php artisan optimize:clear --no-interaction 2>/dev/null \
        || docker exec "$ctr" php artisan optimize:clear --no-interaction 2>/dev/null \
        || true
    fi
  fi
  ok "$(basename "$dest")"
}

collect_dests() {
  DESTS=()
  if [ "${TARGETS[0]:-}" = "--all-bots" ]; then
    mapfile -t DESTS < <(list_bot_dests)
  elif [ "${TARGETS[0]:-}" = "--all" ]; then
    mapfile -t DESTS < <(list_all_dests)
  else
    local t resolved
    for t in "${TARGETS[@]}"; do
      resolved="$(resolve_dest "$t")" || err "نمونه پیدا نشد: $t"
      DESTS+=("$resolved")
    done
  fi
  [ ${#DESTS[@]} -gt 0 ] || err "هیچ نمونه‌ای در deploy/instances نیست"
}

cd "$ROOT"
[ -d .git ] && info "git pull …" && git pull --ff-only || warn "git pull رد شد — ادامه با کد فعلی"

collect_dests

n=0
for dest in "${DESTS[@]}"; do
  n=$((n + 1))
  if [ "$n" -gt 1 ] && [ "$DO_BUILD" = 1 ]; then
    DO_BUILD=0
    info "build فقط برای نمونهٔ اول بود؛ بقیه با image موجود recreate می‌شوند (--no-build)"
  fi
  compose_up_web "$dest"
done

ok "تمام — ${#DESTS[@]} نمونه"
