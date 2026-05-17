#!/usr/bin/env bash
# استقرار امن کد جدید روی همهٔ ربات‌های تلگرام (بدون pickup مثل bale.cyou)
#
#   cd ~/VPNMarket && git pull
#   ./deploy/bin/rollout-all-bots.sh
#
# گزینه‌ها به rebuild-instance-web.sh هم می‌رسد: --no-build --no-clear
#   ./deploy/bin/rollout-all-bots.sh --no-build   # فقط اگر image همین الان build شده
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTANCES_DIR="$ROOT/deploy/instances"
REBUILD="$ROOT/deploy/bin/rebuild-instance-web.sh"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

[ -x "$REBUILD" ] || err "نیست: $REBUILD"

instance_is_pickup_only() {
  local dest="$1"
  grep -qE '^APP_SHARE_PICKUP_ONLY=true' "$dest/.env" 2>/dev/null
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

recreate_workers() {
  local dest="$1"
  local envf="$dest/.env"
  export INSTANCE_ENV_FILE="$(cd "$dest" && pwd)/.env"
  export ENV_FILE="$INSTANCE_ENV_FILE"
  set -a
  # shellcheck disable=SC1090
  source "$INSTANCE_ENV_FILE"
  set +a

  local project="${COMPOSE_PROJECT_NAME:?}"
  local -a files=(
    -f "$ROOT/docker-compose.yml"
    -f "$ROOT/deploy/docker-compose.no-local-db.yml"
    -f "$ROOT/deploy/docker-compose.instance-env.yml"
    -f "$ROOT/deploy/docker-compose.build-root.yml"
    -f "$ROOT/deploy/docker-compose.web-rebuild.yml"
    -f "$ROOT/docker-compose.traefik.yml"
    -f "$dest/docker-compose.yml"
    -f "$dest/docker-compose.mount.yml"
    -f "$ROOT/deploy/docker-compose.bot-workers.yml"
  )

  if docker compose --project-directory "$ROOT" "${files[@]}" \
    --env-file "$INSTANCE_ENV_FILE" -p "$project" config --services 2>/dev/null | grep -qx queue; then
    docker compose --project-directory "$ROOT" "${files[@]}" \
      --env-file "$INSTANCE_ENV_FILE" -p "$project" \
      up -d --force-recreate --no-build queue scheduler 2>/dev/null \
      || docker compose --project-directory "$ROOT" "${files[@]}" \
        --env-file "$INSTANCE_ENV_FILE" -p "$project" \
        up -d --force-recreate --no-build queue 2>/dev/null \
      || true
  fi
}

post_deploy_artisan() {
  local project="$1"
  local domain="$2"
  local ctr="${project}-web-1"

  if ! docker ps --format '{{.Names}}' | grep -qx "$ctr"; then
    warn "web نیست: $ctr — رد شد"
    return 1
  fi

  info "[$domain] migrate"
  docker exec "$ctr" php artisan migrate --force --no-interaction

  if docker exec "$ctr" php artisan list --raw 2>/dev/null | grep -qx 'vpnmarket:sync-renew-eligibility-messages'; then
    info "[$domain] sync renew bot messages"
    docker exec "$ctr" php artisan vpnmarket:sync-renew-eligibility-messages --no-interaction
  else
    warn "[$domain] دستور sync-renew-eligibility نیست — یک بار rebuild با git جدید لازم است"
  fi

  docker exec -u www-data "$ctr" php artisan optimize:clear --no-interaction 2>/dev/null \
    || docker exec "$ctr" php artisan optimize:clear --no-interaction 2>/dev/null \
    || true

  ok "[$domain] post-deploy تمام"
}

cd "$ROOT"

info "=== مرحله ۱: rebuild همهٔ web ربات‌ها ==="
"$REBUILD" --all-bots "$@"

mapfile -t DESTS < <(list_bot_dests)
[ ${#DESTS[@]} -gt 0 ] || err "هیچ ربات در deploy/instances نیست"

info "=== مرحله ۲: queue + scheduler (همان image جدید) ==="
for dest in "${DESTS[@]}"; do
  recreate_workers "$dest"
done

info "=== مرحله ۳: migrate + پیام‌های تمدید + cache ==="
failed=0
for dest in "${DESTS[@]}"; do
  domain="$(basename "$dest")"
  # shellcheck disable=SC1090
  set -a && source "$dest/.env" && set +a
  post_deploy_artisan "${COMPOSE_PROJECT_NAME}" "$domain" || failed=$((failed + 1))
done

echo ""
if [ "$failed" -gt 0 ]; then
  warn "$failed نمونه خطا داشت — لاگ web را ببینید"
  exit 1
fi

ok "استقرار روی ${#DESTS[@]} ربات تمام شد (pickup / Traefik / MySQL دست نخورده)"
