#!/usr/bin/env bash
# بکاپ دادهٔ tenant یک ربات (برای قالب اولیهٔ ربات‌های جدید)
# داده در MySQL مشترک است — نه داخل کانتینر web.
#
# استفاده:
#   ./deploy/bin/export-instance-template.sh robot.bypax.store /root/templates/robot-initial.sql
#   ./deploy/bin/export-instance-template.sh robot.bypax.store   # پیش‌فرض: /root/templates/<instance_id>.sql
#
# ربات جدید:
#   ./deploy/bin/bootstrap-new-bot-from-template.sh /root/templates/robot-initial.sql newbot.bypax.store
#
# گزینه‌ها:
#   --mysql-container NAME
#   --skip-settings          جدول settings در فایل نباشد (توکن هر ربات جدا بماند)
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

MYSQL_C="${MYSQL_C:-vpnmarket_shared_mysql}"
SOURCE=""
OUT_FILE=""
SKIP_SETTINGS=0

TENANT_TABLES=(
  users plans settings inbounds bot_messages discount_codes
  orders transactions discount_code_usages notifications user_trials
  tickets ticket_replies
)

while [ $# -gt 0 ]; do
  case "$1" in
    --mysql-container) MYSQL_C="${2:?}"; shift 2 ;;
    --skip-settings) SKIP_SETTINGS=1; shift ;;
    -h|--help)
      sed -n '1,14p' "$0" | sed 's/^# \?//'
      exit 0
      ;;
    -*)
      err "آرگومنت ناشناس: $1"
      ;;
    *)
      if [ -z "$SOURCE" ]; then SOURCE="$1"
      elif [ -z "$OUT_FILE" ]; then OUT_FILE="$1"
      else err "آرگومنت اضافه: $1"
      fi
      shift
      ;;
  esac
done

[ -n "$SOURCE" ] || err "دامنه یا .env مبدأ را بدهید: export-instance-template.sh robot.bypax.store"

resolve_env_file() {
  local x="$1"
  if [ -f "$x" ]; then
    printf '%s\n' "$(cd "$(dirname "$x")" && pwd)/$(basename "$x")"
    return 0
  fi
  local d="$ROOT/deploy/instances/$x/.env"
  [ -f "$d" ] || err "نه فایل است نه دامنه: $x"
  printf '%s\n' "$(cd "$(dirname "$d")" && pwd)/$(basename "$d")"
}

load_env() {
  grep -E "^${2}=" "$1" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

SOURCE_ENV="$(resolve_env_file "$SOURCE")"
INSTANCE_ID="$(load_env "$SOURCE_ENV" APP_INSTANCE_ID)"
DB="$(load_env "$SOURCE_ENV" DB_DATABASE)"
DOMAIN="$(load_env "$SOURCE_ENV" APP_DOMAIN)"
MYSQL_ROOT_PASSWORD="$(load_env "$SOURCE_ENV" MYSQL_ROOT_PASSWORD)"

[ -n "$INSTANCE_ID" ] && [ -n "$DB" ] && [ -n "$MYSQL_ROOT_PASSWORD" ] || err "APP_INSTANCE_ID / DB_DATABASE / MYSQL_ROOT_PASSWORD در .env خالی است"

if [ -z "$OUT_FILE" ]; then
  mkdir -p /root/templates 2>/dev/null || true
  OUT_FILE="/root/templates/${INSTANCE_ID}.sql"
fi

OUT_DIR="$(dirname "$OUT_FILE")"
mkdir -p "$OUT_DIR"
OUT_FILE="$(cd "$OUT_DIR" && pwd)/$(basename "$OUT_FILE")"

docker ps --format '{{.Names}}' | grep -qx "$MYSQL_C" || err "MySQL نیست: $MYSQL_C"

escape_sql() { printf '%s' "$1" | sed "s/'/''/g"; }
SQL_IID="$(escape_sql "$INSTANCE_ID")"

mysql_dump() {
  docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" \
    mysqldump -uroot --single-transaction --quick --hex-blob --skip-add-locks --no-tablespaces "$@"
}

table_exists() {
  local tbl="$1" n
  n="$(docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" mysql -uroot -N -e "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA='$(escape_sql "$DB")' AND TABLE_NAME='$(escape_sql "$tbl")'
  ")"
  [ "${n:-0}" -gt 0 ]
}

has_instance_id() {
  local tbl="$1" n
  n="$(docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" mysql -uroot -N -e "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$(escape_sql "$DB")' AND TABLE_NAME='$(escape_sql "$tbl")' AND COLUMN_NAME='instance_id'
  ")"
  [ "${n:-0}" -gt 0 ]
}

info "بکاپ tenant: $DOMAIN ($INSTANCE_ID) → $OUT_FILE"

{
  echo "-- VPNMarket instance template"
  echo "-- source_domain: $DOMAIN"
  echo "-- source_instance_id: $INSTANCE_ID"
  echo "-- database: $DB"
  echo "-- generated: $(date -Iseconds)"
  echo "SET NAMES utf8mb4;"
  echo "SET FOREIGN_KEY_CHECKS=0;"
  echo ""
} >"$OUT_FILE"

TABLES_SCHEMA=()
for tbl in "${TENANT_TABLES[@]}"; do
  [ "$SKIP_SETTINGS" = 1 ] && [ "$tbl" = "settings" ] && continue
  table_exists "$tbl" || continue
  has_instance_id "$tbl" || continue
  TABLES_SCHEMA+=("$tbl")
done
table_exists service_shares && TABLES_SCHEMA+=(service_shares)

if [ ${#TABLES_SCHEMA[@]} -eq 0 ]; then
  err "هیچ جدول tenant برای export پیدا نشد"
fi

info "ساختار جداول (${#TABLES_SCHEMA[@]} جدول)…"
mysql_dump --no-data "$DB" "${TABLES_SCHEMA[@]}" >>"$OUT_FILE"

info "دادهٔ tenant…"
for tbl in "${TENANT_TABLES[@]}"; do
  [ "$SKIP_SETTINGS" = 1 ] && [ "$tbl" = "settings" ] && continue
  table_exists "$tbl" || continue
  has_instance_id "$tbl" || continue
  n="$(docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" mysql -uroot -N -e "
    SELECT COUNT(*) FROM \`$(escape_sql "$DB")\`.\`$(escape_sql "$tbl")\` WHERE instance_id='${SQL_IID}'
  ")"
  info "  $tbl: ${n:-0} ردیف"
  [ "${n:-0}" -eq 0 ] && continue
  mysql_dump --no-create-info --complete-insert \
    --where="instance_id='${SQL_IID}'" \
    "$DB" "$tbl" >>"$OUT_FILE"
done

if table_exists service_shares; then
  n="$(docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" mysql -uroot -N -e "
    SELECT COUNT(*) FROM \`$(escape_sql "$DB")\`.\`service_shares\` s
    INNER JOIN \`$(escape_sql "$DB")\`.\`users\` u ON u.id = s.user_id
    WHERE u.instance_id='${SQL_IID}'
       OR s.source_instance_id='${SQL_IID}'
  ")"
  info "  service_shares: ${n:-0} ردیف"
  if [ "${n:-0}" -gt 0 ]; then
    mysql_dump --no-create-info --complete-insert \
      --where="source_instance_id='${SQL_IID}' OR user_id IN (SELECT id FROM \`$(escape_sql "$DB")\`.users WHERE instance_id='${SQL_IID}')" \
      "$DB" service_shares >>"$OUT_FILE" 2>/dev/null || \
    mysql_dump --no-create-info --complete-insert \
      --where="user_id IN (SELECT id FROM users WHERE instance_id='${SQL_IID}')" \
      "$DB" service_shares >>"$OUT_FILE"
  fi
fi

echo "SET FOREIGN_KEY_CHECKS=1;" >>"$OUT_FILE"

BYTES="$(wc -c <"$OUT_FILE" | tr -d ' ')"
ok "ذخیره شد: $OUT_FILE ($BYTES bytes)"
warn "توکن تلگرام و webhook را بعد از import برای ربات جدید جدا تنظیم کنید (یا --skip-settings)."
