#!/usr/bin/env bash
# همگام‌سازی جدول bot_messages از یک instance به دیگری (بر اساس key، نه id).
# برای رفع مشکل بعد از clone/move یا پیام‌های قدیمی با instance_id=default
#
#   ./deploy/bin/sync-bot-messages.sh aof.bypax.store robot.bypax.store
#   ./deploy/bin/sync-bot-messages.sh aof.bypax.store robot.bypax.store --include-default
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
INCLUDE_DEFAULT=0
SKIP_CONFIRM=0
NO_ARTISAN=0

while [ $# -gt 0 ]; do
  case "$1" in
    --mysql-container) MYSQL_C="${2:?}"; shift 2 ;;
    --include-default) INCLUDE_DEFAULT=1; shift ;;
    --skip-confirm) SKIP_CONFIRM=1; shift ;;
    --no-artisan) NO_ARTISAN=1; shift ;;
    -h|--help)
      echo "Usage: sync-bot-messages.sh SRC DST [--include-default] [--mysql-container NAME]"
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

[ -n "$SRC_ENV" ] && [ -n "$DST_ENV" ] || err "مبدأ و مقصد لازم است"

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

SRC_ENV="$(resolve_env_file "$SRC_ENV")"
DST_ENV="$(resolve_env_file "$DST_ENV")"

load_env() {
  local f="$1" var="$2"
  grep -E "^${var}=" "$f" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

SRC_ID="$(load_env "$SRC_ENV" APP_INSTANCE_ID)"
DST_ID="$(load_env "$DST_ENV" APP_INSTANCE_ID)"
DB="$(load_env "$SRC_ENV" DB_DATABASE)"
DST_PROJECT="$(load_env "$DST_ENV" COMPOSE_PROJECT_NAME)"
MYSQL_ROOT_PASSWORD="$(load_env "$SRC_ENV" MYSQL_ROOT_PASSWORD)"
[ -n "$MYSQL_ROOT_PASSWORD" ] || MYSQL_ROOT_PASSWORD="$(load_env "$DST_ENV" MYSQL_ROOT_PASSWORD)"

[ -n "$SRC_ID" ] && [ -n "$DST_ID" ] && [ -n "$DB" ] || err "APP_INSTANCE_ID یا DB_DATABASE خالی است"
[ "$SRC_ID" != "$DST_ID" ] || err "مبدأ و مقصد یکی هستند"

docker ps --format '{{.Names}}' | grep -qx "$MYSQL_C" || err "MySQL نیست: $MYSQL_C"

escape_sql() { printf '%s' "$1" | sed "s/'/''/g"; }
SQL_SRC="$(escape_sql "$SRC_ID")"
SQL_DST="$(escape_sql "$DST_ID")"
SQL_DB="$(escape_sql "$DB")"

mysql_root() {
  docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" -i "$MYSQL_C" mysql -uroot "$@"
}

upsert_from_instance() {
  local from_id="$1"
  mysql_root "$DB" <<EOF
INSERT INTO \`${SQL_DB}\`.\`bot_messages\`
  (instance_id, \`key\`, category, title, content, description, is_active, created_at, updated_at)
SELECT
  '${SQL_DST}', \`key\`, category, title, content, description, is_active, created_at, updated_at
FROM \`${SQL_DB}\`.\`bot_messages\`
WHERE instance_id='$(escape_sql "$from_id")'
ON DUPLICATE KEY UPDATE
  category = VALUES(category),
  title = VALUES(title),
  content = VALUES(content),
  description = VALUES(description),
  is_active = VALUES(is_active),
  updated_at = VALUES(updated_at);
EOF
}

count_for() {
  mysql_root -N -e "SELECT COUNT(*) FROM \`${SQL_DB}\`.\`bot_messages\` WHERE instance_id='$(escape_sql "$1")';"
}

if [ "$SKIP_CONFIRM" != 1 ]; then
  warn "همگام‌سازی bot_messages: $SRC_ID → $DST_ID (دیتابیس: $DB)"
  read -r -p "ادامه؟ [y/N] " ans
  case "$ans" in y|Y|yes|YES) ;; *) err "لغو" ;; esac
fi

info "حذف ردیف‌های تکراری (همان instance_id + key)…"
mysql_root "$DB" <<EOF
DELETE b1 FROM \`${SQL_DB}\`.\`bot_messages\` AS b1
INNER JOIN \`${SQL_DB}\`.\`bot_messages\` AS b2
  ON b1.instance_id = b2.instance_id AND b1.\`key\` = b2.\`key\` AND b1.id < b2.id;
EOF

info "قبل — مبدأ: $(count_for "$SRC_ID") | مقصد: $(count_for "$DST_ID") | default: $(count_for "default")"

if [ "$INCLUDE_DEFAULT" = 1 ]; then
  info "کپی از instance_id=default (سپس بازنویسی با مبدأ)"
  upsert_from_instance "default"
fi

n_src="$(count_for "$SRC_ID")"
if [ "${n_src:-0}" -gt 0 ]; then
  info "کپی از $SRC_ID"
  upsert_from_instance "$SRC_ID"
else
  warn "روی مبدأ ($SRC_ID) هیچ bot_messages نیست."
  if [ "$INCLUDE_DEFAULT" != 1 ]; then
    warn "اگر پیام‌ها قدیمی‌اند شاید روی default باشند — دوباره با --include-default اجرا کنید."
  fi
fi

info "بعد — مقصد: $(count_for "$DST_ID")"

WEB_C="${DST_PROJECT}-web-1"
if [ "$NO_ARTISAN" != 1 ] && docker ps --format '{{.Names}}' | grep -qx "$WEB_C"; then
  info "پاک‌سازی کش Redis/لاراول در $WEB_C"
  docker exec -u www-data "$WEB_C" php artisan cache:clear --no-interaction 2>/dev/null \
    || docker exec "$WEB_C" php artisan cache:clear --no-interaction || true
  docker exec -u www-data "$WEB_C" php artisan tinker --execute="App\\Models\\BotMessage::clearCache();" --no-interaction 2>/dev/null \
    || docker exec "$WEB_C" php artisan tinker --execute="App\\Models\\BotMessage::clearCache();" --no-interaction 2>/dev/null \
    || true
fi

ok "bot_messages برای $DST_ID همگام شد."
