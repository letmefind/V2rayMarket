#!/usr/bin/env bash
# واردکردن بکاپ SQL قدیمی (تک‌tenant) به دیتابیس فعلی یک instance در Docker،
# با رعایت ستون‌های جدید مثل instance_id و بدون دست زدن به migrations/session/queue/cache.
#
# پیش‌نیاز: فایل .env همان instance (یا مسیر به آن)، کانتینر MySQL در حال اجرا، فایل dump.
#
# استفاده:
#   ./deploy/bin/import-legacy-sql-to-instance.sh bypassnet.bypax.store /root/old-tenant.sql
#   ./deploy/bin/import-legacy-sql-to-instance.sh /path/to/instance.env /root/old-tenant.sql
#
# گزینه‌ها:
#   --mysql-container NAME   پیش‌فرض: ${COMPOSE_PROJECT_NAME}-mysql-1
#   --staging-db NAME        پیش‌فرض: freedb_import
#   --dry-run                فقط جداول و دستورات را چاپ کن؛ چیزی روی DB ننویس
#   --keep-staging           بعد از کار staging را حذف نکن
#   --skip-confirm           بدون تأیید تعاملی
#   --skip-settings          جدول settings را از بکاپ کپی نکن (توکن/تنظیمات فعلی ربات حفظ شود)
#   --skip-telegram-settings جدول telegram_bot_settings را کپی نکن
#   --no-artisan             در انتها artisan (config/cache clear) اجرا نشود
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

STAGING_DB="${STAGING_DB:-freedb_import}"
MYSQL_C=""
DUMP_FILE=""
ENV_FILE=""
DRY_RUN=0
KEEP_STAGING=0
SKIP_CONFIRM=0
SKIP_SETTINGS=0
SKIP_TELEGRAM_SETTINGS=0
NO_ARTISAN=0

# همان فهرست tenant در migration: 2026_05_15_140000_add_instance_id_to_tenant_tables.php
TENANT_TABLES="users plans orders settings transactions discount_codes discount_code_usages notifications user_trials inbounds bot_messages tickets ticket_replies"

# جداول لاراول/زمان‌حال که نباید از بکاپ روی prod ست شوند
SKIP_TABLES="migrations sessions cache cache_locks jobs job_batches failed_jobs password_reset_tokens"

# ترتیب تقریبی وابستگی FK (بقیهٔ جداول مشترک در انتها الفبایی)
TABLE_ORDER=(
  users plans settings blog_categories blog_posts
  discount_codes bot_messages user_trials inbounds
  orders transactions discount_code_usages notifications
  tickets ticket_replies service_shares telegram_bot_settings
)

usage() {
  sed -n '1,25p' "$0" | sed -n '/# استفاده:/,/# گزینه‌ها/p' | sed 's/^# //' | head -n -1
  echo "گزینه‌ها: --mysql-container --staging-db --dry-run --keep-staging --skip-confirm --skip-settings --skip-telegram-settings --no-artisan" >&2
}

while [ $# -gt 0 ]; do
  case "$1" in
    --mysql-container) MYSQL_C="${2:?}"; shift 2 ;;
    --staging-db) STAGING_DB="${2:?}"; shift 2 ;;
    --dump) DUMP_FILE="${2:?}"; shift 2 ;;
    --dry-run) DRY_RUN=1; shift ;;
    --keep-staging) KEEP_STAGING=1; shift ;;
    --skip-confirm) SKIP_CONFIRM=1; shift ;;
    --skip-settings) SKIP_SETTINGS=1; shift ;;
    --skip-telegram-settings) SKIP_TELEGRAM_SETTINGS=1; shift ;;
    --no-artisan) NO_ARTISAN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    -*)
      err "آرگومنت ناشناس: $1"
      ;;
    *)
      if [ -z "$ENV_FILE" ]; then
        ENV_FILE="$1"
      elif [ -z "$DUMP_FILE" ]; then
        DUMP_FILE="$1"
      else
        err "آرگومنت اضافه: $1"
      fi
      shift
      ;;
  esac
done

[ -n "$ENV_FILE" ] || err "مسیر دامنه یا .env را بدهید."
[ -n "$DUMP_FILE" ] || err "مسیر فایل dump (مثلاً /root/old-tenant.sql) را بدهید."

resolve_env_file() {
  local x="$1"
  if [ -f "$x" ]; then
    printf '%s\n' "$(cd "$(dirname "$x")" && pwd)/$(basename "$x")"
    return 0
  fi
  local d="$ROOT/deploy/instances/$x/.env"
  [ -f "$d" ] || err "نه فایل است نه دامنهٔ نمونه: $x — انتظار: $d"
  printf '%s\n' "$(cd "$(dirname "$d")" && pwd)/$(basename "$d")"
}

ENV_FILE="$(resolve_env_file "$ENV_FILE")"
[ -f "$DUMP_FILE" ] || err "فایل dump پیدا نشد: $DUMP_FILE"

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

[ -n "${DB_DATABASE:-}" ] || err "DB_DATABASE در $ENV_FILE خالی است"
[ -n "${MYSQL_ROOT_PASSWORD:-}" ] || err "MYSQL_ROOT_PASSWORD در $ENV_FILE خالی است"

INSTANCE_ID="${APP_INSTANCE_ID:-}"
[ -n "$INSTANCE_ID" ] || err "APP_INSTANCE_ID در $ENV_FILE خالی است — برای multi-tenant لازم است"

if [ -z "$MYSQL_C" ]; then
  [ -n "${COMPOSE_PROJECT_NAME:-}" ] || err "COMPOSE_PROJECT_NAME در .env نیست — --mysql-container بدهید"
  MYSQL_C="${COMPOSE_PROJECT_NAME}-mysql-1"
fi

docker ps --format '{{.Names}}' | grep -qx "$MYSQL_C" || err "کانتینر MySQL در حال اجرا نیست: $MYSQL_C"

escape_sql() {
  printf '%s' "$1" | sed "s/'/''/g"
}
SQL_INSTANCE_ID="$(escape_sql "$INSTANCE_ID")"

mysql_root() {
  docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" -i "$MYSQL_C" mysql -uroot "$@"
}

is_tenant_table() {
  local t="$1"
  [[ " $TENANT_TABLES " == *" $t "* ]]
}

should_skip_table() {
  local t="$1"
  [[ " $SKIP_TABLES " == *" $t "* ]] && return 0
  [ "$SKIP_SETTINGS" = 1 ] && [ "$t" = "settings" ] && return 0
  [ "$SKIP_TELEGRAM_SETTINGS" = 1 ] && [ "$t" = "telegram_bot_settings" ] && return 0
  return 1
}

table_has_column() {
  local schema="$1" table="$2" col="$3" n
  n="$(mysql_root -N -e "
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$(escape_sql "$schema")' AND TABLE_NAME='$(escape_sql "$table")' AND COLUMN_NAME='$(escape_sql "$col")'
  ")"
  [ "${n:-0}" -gt 0 ]
}

table_has_primary_key() {
  local schema="$1" table="$2" n
  n="$(mysql_root -N -e "
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA='$(escape_sql "$schema")' AND TABLE_NAME='$(escape_sql "$table")'
      AND CONSTRAINT_TYPE='PRIMARY KEY'
  ")"
  [ "${n:-0}" -gt 0 ]
}

# ستون‌های مشترک به‌ترتیب ordinal در staging
common_columns_ordered() {
  local dst="$1" tbl="$2"
  mysql_root -N -e "
    SELECT c1.COLUMN_NAME
    FROM information_schema.COLUMNS c1
    INNER JOIN information_schema.COLUMNS c2
      ON c2.TABLE_SCHEMA='$(escape_sql "$dst")'
     AND c2.TABLE_NAME='$(escape_sql "$tbl")'
     AND c2.COLUMN_NAME=c1.COLUMN_NAME
    WHERE c1.TABLE_SCHEMA='$(escape_sql "$STAGING_DB")' AND c1.TABLE_NAME='$(escape_sql "$tbl")'
    ORDER BY c1.ORDINAL_POSITION
  "
}

dest_columns_ordered() {
  local dst="$1" tbl="$2"
  mysql_root -N -e "
    SELECT COLUMN_NAME FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA='$(escape_sql "$dst")' AND TABLE_NAME='$(escape_sql "$tbl")'
    ORDER BY ORDINAL_POSITION
  "
}

primary_key_columns() {
  local dst="$1" tbl="$2"
  mysql_root -N -e "
    SELECT k.COLUMN_NAME
    FROM information_schema.KEY_COLUMN_USAGE k
    INNER JOIN information_schema.TABLE_CONSTRAINTS t
      ON t.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND t.TABLE_SCHEMA=k.TABLE_SCHEMA AND t.TABLE_NAME=k.TABLE_NAME
    WHERE k.TABLE_SCHEMA='$(escape_sql "$dst")' AND k.TABLE_NAME='$(escape_sql "$tbl")'
      AND t.CONSTRAINT_TYPE='PRIMARY KEY'
    ORDER BY k.ORDINAL_POSITION
  "
}

sorted_table_list() {
  local -n raw="$1"
  local -n out="$2"
  out=()
  local t u seen=()
  for t in "${TABLE_ORDER[@]}"; do
    if [[ " ${raw[*]} " == *" $t "* ]]; then
      out+=("$t")
      seen+=("$t")
    fi
  done
  local rest=()
  for t in "${raw[@]}"; do
    [[ " ${seen[*]} " == *" $t "* ]] && continue
    rest+=("$t")
  done
  mapfile -t rest_sorted < <(printf '%s\n' "${rest[@]}" | sort -u)
  for t in "${rest_sorted[@]:-}"; do
    [ -n "$t" ] || continue
    out+=("$t")
  done
}

list_common_tables() {
  mysql_root -N -e "
    SELECT i.TABLE_NAME
    FROM information_schema.TABLES i
    INNER JOIN information_schema.TABLES d
      ON d.TABLE_SCHEMA='$(escape_sql "$DB_DATABASE")'
     AND d.TABLE_NAME=i.TABLE_NAME
     AND d.TABLE_TYPE='BASE TABLE'
    WHERE i.TABLE_SCHEMA='$(escape_sql "$STAGING_DB")' AND i.TABLE_TYPE='BASE TABLE'
    ORDER BY i.TABLE_NAME
  "
}

build_merge_for_table() {
  local tbl="$1"
  local -n _sqlout="$2"

  if should_skip_table "$tbl"; then
    _sqlout=""
    return 1
  fi
  if ! table_has_primary_key "$DB_DATABASE" "$tbl"; then
    warn "جدول $tbl کلید اصلی ندارد — رد شد"
    _sqlout=""
    return 1
  fi

  local dest_has_instance dest_has_src_inst
  dest_has_instance=0
  dest_has_src_inst=0
  table_has_column "$DB_DATABASE" "$tbl" "instance_id" && dest_has_instance=1
  table_has_column "$DB_DATABASE" "$tbl" "source_instance_id" && dest_has_src_inst=1

  mapfile -t dest_cols < <(dest_columns_ordered "$DB_DATABASE" "$tbl")
  mapfile -t pk_cols < <(primary_key_columns "$DB_DATABASE" "$tbl")
  [ ${#pk_cols[@]} -gt 0 ] || { _sqlout=""; return 1; }

  declare -A common_set=()
  while IFS= read -r c; do [ -z "$c" ] && continue; common_set["$c"]=1; done < <(common_columns_ordered "$DB_DATABASE" "$tbl")

  local insert_cols=() select_exprs=()
  local col
  for col in "${dest_cols[@]}"; do
    if is_tenant_table "$tbl" && [ "$dest_has_instance" = 1 ] && [ "$col" = "instance_id" ]; then
      insert_cols+=("\`$col\`")
      select_exprs+=("'${SQL_INSTANCE_ID}'")
      continue
    fi
    if [ "$tbl" = "service_shares" ] && [ "$dest_has_src_inst" = 1 ] && [ "$col" = "source_instance_id" ]; then
      insert_cols+=("\`$col\`")
      select_exprs+=("'${SQL_INSTANCE_ID}'")
      continue
    fi
    [ -n "${common_set[$col]+x}" ] || continue
    if is_tenant_table "$tbl" && [ "$col" = "instance_id" ] && [ "$dest_has_instance" = 1 ]; then
      continue
    fi
    insert_cols+=("\`$col\`")
    select_exprs+=("i.\`$col\`")
  done

  if [ ${#insert_cols[@]} -eq 0 ]; then
    warn "هیچ ستون مشترکی برای $tbl نیست — رد شد"
    _sqlout=""
    return 1
  fi

  declare -A pk_set=()
  for col in "${pk_cols[@]}"; do pk_set["$col"]=1; done

  local upd_parts=()
  for i in "${!insert_cols[@]}"; do
    col="${insert_cols[$i]}"
    col_plain="${col//\`/}"
    [ -n "${pk_set[$col_plain]+x}" ] && continue
    upd_parts+=("${col}=VALUES(${col})")
  done

  if [ ${#upd_parts[@]} -eq 0 ]; then
    warn "چیزی برای UPDATE در $tbl نیست (فقط PK؟) — رد شد"
    _sqlout=""
    return 1
  fi

  local inserts joined selects updates
  inserts="$(printf '%s,' "${insert_cols[@]}" | sed 's/,$//')"
  selects="$(printf '%s,' "${select_exprs[@]}" | sed 's/,$//')"
  updates="$(IFS=,; echo "${upd_parts[*]}")"

  _sqlout="INSERT INTO \`$(escape_sql "$DB_DATABASE")\`.\`$(escape_sql "$tbl")\` ($inserts)
SELECT $selects
FROM \`$(escape_sql "$STAGING_DB")\`.\`$(escape_sql "$tbl")\` AS i
ON DUPLICATE KEY UPDATE $updates;"
}

if [ "$SKIP_CONFIRM" != 1 ] && [ "$DRY_RUN" != 1 ]; then
  warn "دیتابیس مقصد: $DB_DATABASE | instance_id: $INSTANCE_ID | MySQL: $MYSQL_C"
  warn "Staging: $STAGING_DB از فایل: $DUMP_FILE"
  read -r -p "ادامه می‌دهید؟ [y/N] " ans
  case "$ans" in
    y|Y|yes|YES) ;;
    *) err "لغو شد" ;;
  esac
fi

info "ساخت/خالی‌کردن staging: $STAGING_DB"
mysql_root -e "
  DROP DATABASE IF EXISTS \`$(escape_sql "$STAGING_DB")\`;
  CREATE DATABASE \`$(escape_sql "$STAGING_DB")\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
"

info "ایمپورت dump به staging (ممکن است چند دقیقه طول بکشد)…"
mysql_root --force "$STAGING_DB" <"$DUMP_FILE"

mapfile -t raw_tables < <(list_common_tables)
merge_tables=()
sorted_table_list raw_tables merge_tables

info "جداول مشترک (پس از فیلتر skip):"
printed=0
to_run=()
for tbl in "${merge_tables[@]}"; do
  should_skip_table "$tbl" && { info "  - رد (skip): $tbl"; continue; }
  to_run+=("$tbl")
  printf '  %s\n' "$tbl"
  printed=1
done
[ "$printed" = 1 ] || warn "هیچ جدولی برای ادغام نیست"

SQL_BATCH="$(mktemp)"
trap 'rm -f "$SQL_BATCH"' EXIT

{
  echo "SET NAMES utf8mb4;"
  echo "SET FOREIGN_KEY_CHECKS=0;"
  for tbl in "${to_run[@]}"; do
    q=""
    if build_merge_for_table "$tbl" q && [ -n "$q" ]; then
      echo "$q"
    fi
  done
  echo "SET FOREIGN_KEY_CHECKS=1;"
} >"$SQL_BATCH"

if [ "$DRY_RUN" = 1 ]; then
  info "[dry-run] فقط خروجی SQL (هیچ تغییری روی \`$DB_DATABASE\` اعمال نشد):"
  cat "$SQL_BATCH"
  if [ "$KEEP_STAGING" != 1 ]; then
    info "حذف staging: $STAGING_DB"
    mysql_root -e "DROP DATABASE IF EXISTS \`$(escape_sql "$STAGING_DB")\`;"
  else
    warn "staging نگه داشته شد: $STAGING_DB"
  fi
  exit 0
fi

info "اجرای ادغام (UPSERT) روی \`$DB_DATABASE\`…"
mysql_root --force "$DB_DATABASE" <"$SQL_BATCH"

if [ "$KEEP_STAGING" != 1 ]; then
  info "حذف staging: $STAGING_DB"
  mysql_root -e "DROP DATABASE IF EXISTS \`$(escape_sql "$STAGING_DB")\`;"
else
  warn "staging نگه داشته شد: $STAGING_DB"
fi

WEB_C="${COMPOSE_PROJECT_NAME:-}-web-1"
if [ "$NO_ARTISAN" != 1 ] && docker ps --format '{{.Names}}' | grep -qx "$WEB_C"; then
  info "پاک‌سازی کش لاراول در $WEB_C …"
  docker exec -u www-data "$WEB_C" php artisan config:clear --no-interaction 2>/dev/null || docker exec "$WEB_C" php artisan config:clear --no-interaction
  docker exec -u www-data "$WEB_C" php artisan cache:clear --no-interaction 2>/dev/null || docker exec "$WEB_C" php artisan cache:clear --no-interaction || true
else
  [ "$NO_ARTISAN" = 1 ] || warn "کانتینر وب پیدا نشد ($WEB_C) — artisan را دستی اجرا کنید"
fi

ok "تمام. اگر webhook یا ربات نیاز به تنظیم دارد: telegram:set-webhook / vpnmarket:provision-instance را اجرا کنید."
