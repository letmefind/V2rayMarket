#!/usr/bin/env bash
# اجرای artisan داخل کانتینر با .env درست (جلوگیری از fallback به sqlite)
#
#   ./deploy/bin/docker-artisan.sh vpnmarket_robot_bypax_store-web-1 migrate --force
#   ./deploy/bin/docker-artisan.sh vpnmarket_robot_bypax_store-web-1 vpnmarket:diagnose-xmplus-renewal
#
set -euo pipefail

[ $# -ge 2 ] || {
  echo "Usage: $0 <container-name> <artisan-args...>" >&2
  exit 1
}

CTR="$1"
shift

docker ps --format '{{.Names}}' | grep -qx "$CTR" || {
  echo "کانتینر در حال اجرا نیست: $CTR" >&2
  exit 1
}

docker exec "$CTR" sh -c '
  set -e
  cd /var/www/html
  if [ -f /run/instance.env ]; then
    cp /run/instance.env .env
    chmod 640 .env 2>/dev/null || true
  fi
  if [ ! -f .env ]; then
    echo "FATAL: .env نیست — fix-instance-db.sh <domain> bot" >&2
    exit 1
  fi
  if ! grep -qE "^DB_CONNECTION=mysql" .env 2>/dev/null; then
    echo "FATAL: DB_CONNECTION=mysql در .env نیست — ./deploy/bin/fix-instance-db.sh <domain> bot" >&2
    grep -E "^DB_" .env 2>/dev/null || true
    exit 1
  fi
  rm -f bootstrap/cache/config.php 2>/dev/null || true
  php artisan config:clear --no-interaction 2>/dev/null || true
'

docker exec "$CTR" php artisan "$@"
