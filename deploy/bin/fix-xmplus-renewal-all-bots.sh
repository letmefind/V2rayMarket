#!/usr/bin/env bash
# وقتی فقط lab تمدید می‌کند: تنظیمات XMPlus renewal را از lab به بقیه کپی + تشخیص
#
#   ./deploy/bin/fix-xmplus-renewal-all-bots.sh
#   FROM=vpnmarket_lab_bypax_store ./deploy/bin/fix-xmplus-renewal-all-bots.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
FROM_INSTANCE="${FROM:-vpnmarket_lab_bypax_store}"
LAB_CTR="${FROM_INSTANCE}-web-1"

TO_INSTANCES=(
  vpnmarket_aof_bypax_store
  vpnmarket_bypassnet_bypax_store
  vpnmarket_raydar_bypax_store
  vpnmarket_robot_bypax_store
)

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }

docker ps --format '{{.Names}}' | grep -qx "$LAB_CTR" || err "کانتینر lab نیست: $LAB_CTR"

if ! docker exec "$LAB_CTR" php artisan list --raw 2>/dev/null | grep -qx 'vpnmarket:sync-xmplus-renewal-settings'; then
  err "کد جدید نیست — اول: git pull && ./deploy/bin/rollout-all-bots.sh"
fi

TO_ARGS=()
for id in "${TO_INSTANCES[@]}"; do
  [ "$id" = "$FROM_INSTANCE" ] && continue
  TO_ARGS+=(--to="$id")
done

info "کپی تنظیمات تمدید از $FROM_INSTANCE به ${#TO_ARGS[@]} ربات"
docker exec "$LAB_CTR" php artisan vpnmarket:sync-xmplus-renewal-settings \
  --from="$FROM_INSTANCE" \
  "${TO_ARGS[@]}"

info "تشخیص هر ربات"
for id in "${TO_INSTANCES[@]}"; do
  ctr="${id}-web-1"
  docker ps --format '{{.Names}}' | grep -qx "$ctr" || continue
  echo ""
  info "=== $id ==="
  docker exec "$ctr" php artisan config:clear --no-interaction 2>/dev/null || true
  docker exec "$ctr" php artisan vpnmarket:diagnose-xmplus-renewal --no-interaction || true
done

printf '\033[1;32m✓\033[0m تمام\n'
