#!/usr/bin/env bash
# حذف ردیف‌های تکراری bot_messages (همان instance_id + key) — برای رفع خطای Filament «کلید قبلاً انتخاب شده»
#
#   ./deploy/bin/fix-duplicate-bot-messages.sh robot.bypax.store
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MYSQL_C="${MYSQL_C:-vpnmarket_shared_mysql}"
TARGET="${1:-}"

[ -n "$TARGET" ] || { echo "Usage: $0 <domain|path-to-.env>" >&2; exit 1; }

resolve_env_file() {
  local x="$1"
  if [ -f "$x" ]; then
    printf '%s\n' "$(cd "$(dirname "$x")" && pwd)/$(basename "$x")"
    return 0
  fi
  local d="$ROOT/deploy/instances/$x/.env"
  [ -f "$d" ] || { echo "نیست: $d" >&2; exit 1; }
  printf '%s\n' "$(cd "$(dirname "$d")" && pwd)/$(basename "$d")"
}

ENV_FILE="$(resolve_env_file "$TARGET")"
load_env() { grep -E "^${2}=" "$1" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"; }

DB="$(load_env "$ENV_FILE" DB_DATABASE)"
IID="$(load_env "$ENV_FILE" APP_INSTANCE_ID)"
MYSQL_ROOT_PASSWORD="$(load_env "$ENV_FILE" MYSQL_ROOT_PASSWORD)"

escape_sql() { printf '%s' "$1" | sed "s/'/''/g"; }

docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" -i "$MYSQL_C" mysql -uroot "$DB" <<EOF
SELECT instance_id, \`key\`, COUNT(*) AS n
FROM \`$(escape_sql "$DB")\`.\`bot_messages\`
WHERE instance_id='$(escape_sql "$IID")'
GROUP BY instance_id, \`key\`
HAVING n > 1;

DELETE b1 FROM \`$(escape_sql "$DB")\`.\`bot_messages\` AS b1
INNER JOIN \`$(escape_sql "$DB")\`.\`bot_messages\` AS b2
  ON b1.instance_id = b2.instance_id AND b1.\`key\` = b2.\`key\` AND b1.id < b2.id
WHERE b1.instance_id='$(escape_sql "$IID")';
EOF

echo "✓ تکراری‌های bot_messages برای $IID حذف شد."
