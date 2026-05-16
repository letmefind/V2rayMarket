#!/usr/bin/env bash
# کپی دادهٔ یک ربات (instance_id مبدأ) به ربات دیگر در همان DB_DATABASE مشترک.
#
# استفاده:
#   ./deploy/bin/clone-instance-data.sh aof.bypax.store robot.bypax.store
#   ./deploy/bin/clone-instance-data.sh deploy/instances/aof.bypax.store/.env deploy/instances/robot.bypax.store/.env
#
# گزینه‌ها:
#   --mysql-container NAME     پیش‌فرض: vpnmarket_shared_mysql
#   --skip-settings            settings مبدأ کپی نشود (توکن ربات مقصد حفظ می‌شود)
#   --skip-confirm
#   --dry-run                  فقط SQL چاپ شود
#   --no-artisan
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

MYSQL_C="${MYSQL_C:-vpnmarket_shared_mysql}"
SRC_ENV=""
DST_ENV=""
SKIP_SETTINGS=0
SKIP_CONFIRM=0
DRY_RUN=0
NO_ARTISAN=0

TENANT_TABLES=(
  users plans settings inbounds bot_messages discount_codes
  orders transactions discount_code_usages notifications user_trials
  tickets ticket_replies
)

# حذف: فرزند قبل از والد
DELETE_ORDER=(
  ticket_replies tickets discount_code_usages notifications user_trials
  transactions orders inbounds discount_codes bot_messages plans settings users
)

# درج: والد قبل از فرزند
INSERT_ORDER=(
  users plans settings inbounds bot_messages discount_codes
  orders transactions discount_code_usages notifications user_trials
  tickets ticket_replies
)

while [ $# -gt 0 ]; do
  case "$1" in
    --mysql-container) MYSQL_C="${2:?}"; shift 2 ;;
    --skip-settings) SKIP_SETTINGS=1; shift ;;
    --skip-confirm) SKIP_CONFIRM=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    --no-artisan) NO_ARTISAN=1; shift ;;
    -h|--help)
      sed -n '1,18p' "$0" | sed 's/^# \?//'
      exit 0
      ;;
    -*)
      err "آرگومنت ناشناس: $1"
      ;;
    *)
      if [ -z "$SRC_ENV" ]; then SRC_ENV="$1"
      elif [ -z "$DST_ENV" ]; then DST_ENV="$1"
      else err "آرگومنت اضافه: $1"
      fi
      shift
      ;;
  esac
done

[ -n "$SRC_ENV" ] && [ -n "$DST_ENV" ] || err "مبدأ و مقصد را بدهید: clone-instance-data.sh aof.bypax.store robot.bypax.store"

resolve_env_file() {
  local x="$1"
  if [ -f "$x" ]; then
    printf '%s\n' "$(cd "$(dirname "$x")" && pwd)/$(basename "$x")"
    return 0
  fi
  local d="$ROOT/deploy/instances/$x/.env"
  [ -f "$d" ] || err "نه فایل است نه دامنه: $x (انتظار $d)"
  printf '%s\n' "$(cd "$(dirname "$d")" && pwd)/$(basename "$d")"
}

SRC_ENV="$(resolve_env_file "$SRC_ENV")"
DST_ENV="$(resolve_env_file "$DST_ENV")"

load_env() {
  local f="$1" var="$2"
  local v
  v="$(grep -E "^${var}=" "$f" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//")"
  printf '%s' "$v"
}

SRC_ID="$(load_env "$SRC_ENV" APP_INSTANCE_ID)"
DST_ID="$(load_env "$DST_ENV" APP_INSTANCE_ID)"
SRC_DB="$(load_env "$SRC_ENV" DB_DATABASE)"
DST_DB="$(load_env "$DST_ENV" DB_DATABASE)"
SRC_PROJECT="$(load_env "$SRC_ENV" COMPOSE_PROJECT_NAME)"
DST_PROJECT="$(load_env "$DST_ENV" COMPOSE_PROJECT_NAME)"
MYSQL_ROOT_PASSWORD="$(load_env "$SRC_ENV" MYSQL_ROOT_PASSWORD)"
[ -n "$MYSQL_ROOT_PASSWORD" ] || MYSQL_ROOT_PASSWORD="$(load_env "$DST_ENV" MYSQL_ROOT_PASSWORD)"

[ -n "$SRC_ID" ] && [ -n "$DST_ID" ] || err "APP_INSTANCE_ID در .env خالی است"
[ -n "$SRC_DB" ] && [ -n "$DST_DB" ] || err "DB_DATABASE خالی است"
[ "$SRC_DB" = "$DST_DB" ] || err "DB_DATABASE مبدأ و مقصد یکی نیست ($SRC_DB ≠ $DST_DB) — این اسکریپت فقط کپی داخل یک دیتابیس است"
[ "$SRC_ID" != "$DST_ID" ] || err "مبدأ و مقصد یک instance_id دارند: $SRC_ID"

docker ps --format '{{.Names}}' | grep -qx "$MYSQL_C" || err "MySQL در حال اجرا نیست: $MYSQL_C"

escape_sql() { printf '%s' "$1" | sed "s/'/''/g"; }
SQL_SRC="$(escape_sql "$SRC_ID")"
SQL_DST="$(escape_sql "$DST_ID")"
SQL_DB="$(escape_sql "$SRC_DB")"

mysql_root() {
  docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" -i "$MYSQL_C" mysql -uroot "$@"
}

table_exists() {
  local tbl="$1" n
  n="$(mysql_root -N -e "
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA='${SQL_DB}' AND TABLE_NAME='$(escape_sql "$tbl")' AND TABLE_TYPE='BASE TABLE'
  ")"
  [ "${n:-0}" -gt 0 ]
}

table_has_column() {
  local tbl="$1" col="$2" n
  n="$(mysql_root -N -e "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='${SQL_DB}' AND TABLE_NAME='$(escape_sql "$tbl")'
      AND COLUMN_NAME='$(escape_sql "$col")'
  ")"
  [ "${n:-0}" -gt 0 ]
}

dest_columns() {
  local tbl="$1"
  mysql_root -N -e "
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='${SQL_DB}' AND TABLE_NAME='$(escape_sql "$tbl")'
    ORDER BY ORDINAL_POSITION
  "
}

build_insert_select() {
  local tbl="$1"
  local cols=() select_parts=()
  local col
  while IFS= read -r col; do
    [ -z "$col" ] && continue
    if [ "$SKIP_SETTINGS" = 1 ] && [ "$tbl" = "settings" ]; then
      return 1
    fi
    cols+=("\`$(escape_sql "$col")\`")
    if [ "$col" = "instance_id" ]; then
      select_parts+=("'${SQL_DST}'")
    else
      select_parts+=("\`$(escape_sql "$col")\`")
    fi
  done < <(dest_columns "$tbl")

  [ ${#cols[@]} -gt 0 ] || return 1

  local col_list sel_list
  col_list="$(IFS=,; echo "${cols[*]}")"
  sel_list="$(IFS=,; echo "${select_parts[*]}")"

  printf 'INSERT INTO `%s`.`%s` (%s)\nSELECT %s\nFROM `%s`.`%s`\nWHERE instance_id='\''%s'\'';\n' \
    "$SQL_DB" "$(escape_sql "$tbl")" "$col_list" "$sel_list" \
    "$SQL_DB" "$(escape_sql "$tbl")" "$SQL_SRC"
}

if [ "$SKIP_CONFIRM" != 1 ] && [ "$DRY_RUN" != 1 ]; then
  warn "مبدأ: $SRC_ID ($SRC_ENV)"
  warn "مقصد: $DST_ID ($DST_ENV)"
  warn "دیتابیس: $SRC_DB | MySQL: $MYSQL_C"
  warn "همهٔ ردیف‌های instance_id مقصد حذف و با دادهٔ مبدأ جایگزین می‌شوند."
  [ "$SKIP_SETTINGS" = 1 ] && warn "settings کپی نمی‌شود (توکن ربات robot حفظ می‌شود)."
  read -r -p "ادامه؟ [y/N] " ans
  case "$ans" in y|Y|yes|YES) ;; *) err "لغو" ;; esac
fi

SQL_FILE="$(mktemp)"
trap 'rm -f "$SQL_FILE"' EXIT

{
  echo "SET NAMES utf8mb4;"
  echo "SET FOREIGN_KEY_CHECKS=0;"

  if table_exists service_shares; then
    echo "DELETE s FROM \`${SQL_DB}\`.\`service_shares\` AS s
INNER JOIN \`${SQL_DB}\`.\`users\` AS u ON u.id = s.user_id
WHERE u.instance_id='${SQL_DST}' OR s.source_instance_id='${SQL_DST}';"
  fi

  for tbl in "${DELETE_ORDER[@]}"; do
    table_exists "$tbl" || continue
    table_has_column "$tbl" instance_id || continue
    if [ "$SKIP_SETTINGS" = 1 ] && [ "$tbl" = "settings" ]; then
      continue
    fi
    echo "DELETE FROM \`${SQL_DB}\`.\`$(escape_sql "$tbl")\` WHERE instance_id='${SQL_DST}';"
  done

  for tbl in "${INSERT_ORDER[@]}"; do
    table_exists "$tbl" || continue
    table_has_column "$tbl" instance_id || continue
    build_insert_select "$tbl" || true
  done

  if table_exists service_shares && table_has_column service_shares source_instance_id; then
    cols=() sel=()
    while IFS= read -r col; do
      [ -z "$col" ] && continue
      cols+=("\`$(escape_sql "$col")\`")
      if [ "$col" = "source_instance_id" ]; then
        sel+=("'${SQL_DST}'")
      else
        sel+=("s.\`$(escape_sql "$col")\`")
      fi
    done < <(dest_columns service_shares)
    col_list="$(IFS=,; echo "${cols[*]}")"
    sel_list="$(IFS=,; echo "${sel[*]}")"
    echo "INSERT INTO \`${SQL_DB}\`.\`service_shares\` ($col_list)
SELECT $sel_list
FROM \`${SQL_DB}\`.\`service_shares\` AS s
INNER JOIN \`${SQL_DB}\`.\`users\` AS u ON u.id = s.user_id
WHERE u.instance_id='${SQL_SRC}';"
  fi

  echo "SET FOREIGN_KEY_CHECKS=1;"
} >"$SQL_FILE"

if [ "$DRY_RUN" = 1 ]; then
  info "[dry-run] SQL:"
  cat "$SQL_FILE"
  exit 0
fi

info "اجرای کپی…"
mysql_root --force "$SRC_DB" <"$SQL_FILE"

WEB_C="${DST_PROJECT}-web-1"
if [ "$NO_ARTISAN" != 1 ] && docker ps --format '{{.Names}}' | grep -qx "$WEB_C"; then
  info "پاک‌سازی کش در $WEB_C"
  docker exec -u www-data "$WEB_C" php artisan config:clear --no-interaction 2>/dev/null \
    || docker exec "$WEB_C" php artisan config:clear --no-interaction
  docker exec -u www-data "$WEB_C" php artisan cache:clear --no-interaction 2>/dev/null \
    || docker exec "$WEB_C" php artisan cache:clear --no-interaction || true
fi

ok "کپی تمام شد: $SRC_ID → $DST_ID"
warn "اگر ربات robot توکن جدا دارد، با --skip-settings اجرا کنید یا بعداً توکن را در Filament/DB تنظیم کنید."
