#!/usr/bin/env bash
# همگام‌سازی تنظیمات تمدید XMPlus بین ربات‌های **همین سرور** (MySQL مشترک).
# lab روی سرور دیگر است — از export/import استفاده کنید (deploy/README.md).
#
#   FROM=vpnmarket_aof_bypax_store ./deploy/bin/fix-xmplus-renewal-all-bots.sh
#   ./deploy/bin/fix-xmplus-renewal-all-bots.sh /root/xmplus-renewal.json
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MYSQL_C="${MYSQL_C:-vpnmarket_shared_mysql}"
IMPORT_JSON="${1:-}"

BOT_INSTANCES=(
  vpnmarket_aof_bypax_store
  vpnmarket_bypassnet_bypax_store
  vpnmarket_raydar_bypax_store
  vpnmarket_robot_bypax_store
)

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

pick_ref_container() {
  local id ctr
  if [ -n "${FROM:-}" ]; then
    ctr="${FROM}-web-1"
    docker ps --format '{{.Names}}' | grep -qx "$ctr" && printf '%s\n' "$ctr" && return 0
    err "کانتینر مبدأ نیست: $ctr"
  fi
  for id in "${BOT_INSTANCES[@]}"; do
    ctr="${id}-web-1"
    docker ps --format '{{.Names}}' | grep -qx "$ctr" || continue
    if docker exec "$ctr" php artisan vpnmarket:diagnose-xmplus-renewal --no-interaction >/dev/null 2>&1; then
      printf '%s\n' "$ctr"
      return 0
    fi
  done
  return 1
}

load_mysql_root() {
  local envf="$ROOT/deploy/instances/robot.bypax.store/.env"
  for f in "$ROOT/deploy/instances"/*/.env; do
    [ -f "$f" ] || continue
    envf="$f"
    break
  done
  # shellcheck disable=SC1090
  set -a && source "$envf" && set +a
  [ -n "${MYSQL_ROOT_PASSWORD:-}" ] || err "MYSQL_ROOT_PASSWORD در .env نیست"
}

find_sync_enabled_instance() {
  load_mysql_root
  docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" mysql -uroot -N -e "
    SELECT instance_id FROM \`${DB_DATABASE}\`.settings
    WHERE \`key\`='xmplus_invoice_db_sync_enabled'
      AND value IN ('1','true','yes','on')
    LIMIT 1;
  " 2>/dev/null | head -1
}

REF_CTR=""
if [ -n "$IMPORT_JSON" ]; then
  [ -f "$IMPORT_JSON" ] || err "فایل JSON نیست: $IMPORT_JSON"
  REF_CTR="$(pick_ref_container 2>/dev/null || true)"
  [ -n "$REF_CTR" ] || REF_CTR="${BOT_INSTANCES[0]}-web-1"
  docker ps --format '{{.Names}}' | grep -qx "$REF_CTR" || err "هیچ web-1 در حال اجرا نیست"
  info "import JSON → همهٔ ربات‌های این سرور"
  TO_ARGS=()
  for id in "${BOT_INSTANCES[@]}"; do
    TO_ARGS+=(--to="$id")
  done
  docker cp "$IMPORT_JSON" "$REF_CTR:/tmp/xmplus-renewal.json"
  docker exec "$REF_CTR" php artisan vpnmarket:import-xmplus-renewal-settings \
    /tmp/xmplus-renewal.json "${TO_ARGS[@]}"
else
  FROM_ID="$(find_sync_enabled_instance || true)"
  if [ -z "$FROM_ID" ]; then
    warn "هیچ ربات با xmplus_invoice_db_sync_enabled روی این سرور نیست."
    warn "روی سرور lab:"
    warn "  docker exec ... php artisan vpnmarket:export-xmplus-renewal-settings -o /root/xmplus-renewal.json"
    warn "سپس scp به این سرور و:"
    warn "  $0 /root/xmplus-renewal.json"
    exit 1
  fi
  FROM="${FROM_ID}"
  REF_CTR="${FROM}-web-1"
  info "مبدأ (همین سرور): $FROM"
  TO_ARGS=()
  for id in "${BOT_INSTANCES[@]}"; do
    [ "$id" = "$FROM" ] && continue
    TO_ARGS+=(--to="$id")
  done
  docker exec "$REF_CTR" php artisan vpnmarket:sync-xmplus-renewal-settings \
    --from="$FROM" "${TO_ARGS[@]}"
fi

info "تشخیص هر ربات"
for id in "${BOT_INSTANCES[@]}"; do
  ctr="${id}-web-1"
  docker ps --format '{{.Names}}' | grep -qx "$ctr" || continue
  echo ""
  info "=== $id ==="
  docker exec "$ctr" php artisan config:clear --no-interaction 2>/dev/null || true
  docker exec "$ctr" php artisan vpnmarket:diagnose-xmplus-renewal --no-interaction || true
done

printf '\033[1;32m✓\033[0m تمام\n'
