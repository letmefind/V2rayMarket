#!/usr/bin/env bash
# تنظیم passwd = MD5(رمز) در جدول user دیتابیس XMPlus + ذخیره در VPNMarket
#
#   XMPLUS_UNIFIED_PASSWORD='pNet2026!Xm' \
#   XMPLUS_DB_HOST=127.0.0.1 \
#   XMPLUS_DB_NAME='admin_web.admin_xmplus' \
#   XMPLUS_DB_USER=root \
#   XMPLUS_DB_PASSWORD='...' \
#   ./deploy/bin/xmplus-mysql-unified-password.sh bypassnet.bypax.store
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTANCE_ARG="${1:-}"
shift || true

[ -n "$INSTANCE_ARG" ] || { echo "usage: $0 DOMAIN [--dry-run]" >&2; exit 1; }

DRY=0
[ "${1:-}" = "--dry-run" ] && DRY=1

PASS="${XMPLUS_UNIFIED_PASSWORD:-}"
[ -n "$PASS" ] || { echo "XMPLUS_UNIFIED_PASSWORD required" >&2; exit 1; }

XM_HOST="${XMPLUS_DB_HOST:-}"
XM_DB="${XMPLUS_DB_NAME:-}"
XM_USER="${XMPLUS_DB_USER:-root}"
XM_PASS="${XMPLUS_DB_PASSWORD:-}"

resolve_dest() {
  local name="$1"
  local d="$ROOT/deploy/instances/$name"
  [ -f "$d/.env" ] && printf '%s\n' "$d/.env" && return 0
  return 1
}

ENV_FILE="$(resolve_dest "$INSTANCE_ARG")" || { echo "instance not found: $INSTANCE_ARG" >&2; exit 1; }
set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

INSTANCE_ID="${APP_INSTANCE_ID:?}"
CTR="${COMPOSE_PROJECT_NAME:?}-web-1"

if [ -z "$XM_HOST" ] || [ -z "$XM_DB" ]; then
  echo "Set XMPLUS_DB_HOST and XMPLUS_DB_NAME (database where XMPlus table user lives)" >&2
  exit 1
fi

MD5_HASH="$(docker exec -e PASS="$PASS" "$CTR" php -r 'echo md5((string) getenv("PASS"));')"
echo "MD5(passwd)=$MD5_HASH"

EMAILS="$(docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD:?}" vpnmarket_shared_mysql \
  mysql -uroot "${DB_DATABASE:?}" -N -e "
SELECT LOWER(xmplus_client_email) FROM users
WHERE instance_id = '${INSTANCE_ID}'
  AND xmplus_client_email IS NOT NULL AND xmplus_client_email != ''
  AND (
    xmplus_client_password IS NULL OR xmplus_client_password = ''
    OR xmplus_client_password LIKE '{%'
  );
")"

COUNT="$(echo "$EMAILS" | grep -c . || true)"
[ "$COUNT" -gt 0 ] || { echo "no emails to update"; exit 0; }

IN_LIST="$(echo "$EMAILS" | sed "s/^/'/;s/$/'/" | paste -sd, -)"
SQL="UPDATE user SET passwd = '${MD5_HASH}' WHERE LOWER(email) IN (${IN_LIST});"

if [ "$DRY" = 1 ]; then
  echo "$SQL" | head -c 500
  echo "..."
  echo "would update ~${COUNT} emails in XMPlus DB"
  exit 0
fi

docker run --rm -i mysql:8.4 mysql \
  -h"$XM_HOST" -u"$XM_USER" -p"$XM_PASS" "$XM_DB" -e "$SQL"

echo "XMPlus user.passwd updated: ${COUNT}"

XMPLUS_UNIFIED_PASSWORD="$PASS" \
  "$ROOT/deploy/bin/xmplus-assign-unified-password.sh" "$INSTANCE_ARG" --password="$PASS"

echo "done — run with --verify on assign script if needed"
