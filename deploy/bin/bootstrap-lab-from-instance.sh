#!/usr/bin/env bash
# کپی کامل دادهٔ یک ربات (مثلاً aof) به نمونهٔ lab برای تست — بدون دست زدن به مبدأ.
#
#   ./deploy/bin/bootstrap-lab-from-instance.sh aof.bypax.store lab.bypax.store
#
# پیش‌نیاز روی سرور lab:
#   - Traefik + MySQL مشترک (vpnmarket_shared_mysql) از قبل بالا
#   - deploy/.provision/cluster.env
#   - DNS: lab.bypax.store → IP سرور
#   - git pull آخرین کد
#
# بعد از اجرا:
#   - توکن ربات تلگرام جدا برای lab (BotFather) در Filament یا:
#     docker exec vpnmarket_lab_bypax_store-web-1 php artisan vpnmarket:provision-instance --token=...
#   - webhook: php artisan telegram:set-webhook
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
# shellcheck source=lib/instance-compose.sh
source "$ROOT/deploy/bin/lib/instance-compose.sh"

EXPORT="$ROOT/deploy/bin/export-instance-template.sh"
IMPORT_BOOT="$ROOT/deploy/bin/bootstrap-new-bot-from-template.sh"
FIX_DB="$ROOT/deploy/bin/fix-instance-db.sh"
REBUILD="$ROOT/deploy/bin/rebuild-instance-web.sh"
CLUSTER="$ROOT/deploy/.provision/cluster.env"
INSTANCES="$ROOT/deploy/instances"

info() { printf '\033[1;36m→\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m!\033[0m %s\n' "$*" >&2; }
err() { printf '\033[1;31m✗\033[0m %s\n' "$*" >&2; exit 1; }
ok() { printf '\033[1;32m✓\033[0m %s\n' "$*"; }

MYSQL_C="${MYSQL_C:-vpnmarket_shared_mysql}"
SRC=""
DST=""
TEMPLATE=""
TEMPLATE_DIR="${TEMPLATE_DIR:-/root/templates}"
SKIP_EXPORT=0
SKIP_UP=0
SKIP_REBUILD=0
SKIP_CONFIRM=0
FORCE_REIMPORT=0
INSTANCE_ID_OVERRIDE=""

usage() {
  sed -n '1,20p' "$0" | sed 's/^# \?//'
  echo ""
  echo "گزینه‌ها:"
  echo "  --mysql-container NAME"
  echo "  --template PATH          از export رد شو؛ همین فایل SQL را import کن"
  echo "  --template-dir DIR       پیش‌فرض: /root/templates"
  echo "  --instance-id ID         APP_INSTANCE_ID مقصد (پیش‌فرض: vpnmarket_<slug دامنه>)"
  echo "  --skip-export --skip-up --skip-rebuild"
  echo "  --skip-confirm --force-reimport"
  echo "  -h|--help"
}

domain_slug() {
  echo "$1" | tr '[:upper:]' '[:lower:]' | sed 's/[^a-z0-9]/_/g' | sed 's/__*/_/g' | sed 's/^_//;s/_$//'
}

normalize_domain() {
  echo "$1" | tr '[:upper:]' '[:lower:]'
}

resolve_env_file() {
  local x="$1"
  if [ -f "$x" ]; then
    printf '%s\n' "$(cd "$(dirname "$x")" && pwd)/$(basename "$x")"
    return 0
  fi
  local d="$INSTANCES/$x/.env"
  [ -f "$d" ] || err "نه فایل است نه دامنه: $x — انتظار $d"
  printf '%s\n' "$(cd "$(dirname "$d")" && pwd)/$(basename "$d")"
}

load_env_var() {
  grep -E "^${2}=" "$1" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' -e "s/^'//" -e "s/'$//"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --mysql-container) MYSQL_C="${2:?}"; shift 2 ;;
    --template) TEMPLATE="${2:?}"; SKIP_EXPORT=1; shift 2 ;;
    --template-dir) TEMPLATE_DIR="${2:?}"; shift 2 ;;
    --instance-id) INSTANCE_ID_OVERRIDE="${2:?}"; shift 2 ;;
    --skip-export) SKIP_EXPORT=1; shift ;;
    --skip-up) SKIP_UP=1; shift ;;
    --skip-rebuild) SKIP_REBUILD=1; shift ;;
    --skip-confirm) SKIP_CONFIRM=1; shift ;;
    --force-reimport) FORCE_REIMPORT=1; shift ;;
    -h|--help) usage; exit 0 ;;
    -*)
      err "آرگومент ناشناس: $1"
      ;;
    *)
      if [ -z "$SRC" ]; then SRC="$1"
      elif [ -z "$DST" ]; then DST="$1"
      else err "آرگومنت اضافه: $1"
      fi
      shift
      ;;
  esac
done

[ -n "$SRC" ] && [ -n "$DST" ] || { usage; exit 1; }
[ -x "$EXPORT" ] || err "نیست: $EXPORT"
[ -x "$IMPORT_BOOT" ] || err "نیست: $IMPORT_BOOT"
[ -x "$FIX_DB" ] || err "نیست: $FIX_DB"
[ -f "$CLUSTER" ] || err "نیست cluster.env — اول ./deploy/install.sh"

SRC_ENV="$(resolve_env_file "$SRC")"
DST_DOMAIN="$(normalize_domain "$DST")"
DST_DIR="$INSTANCES/$DST_DOMAIN"
SRC_ID="$(load_env_var "$SRC_ENV" APP_INSTANCE_ID)"
SRC_DOMAIN="$(load_env_var "$SRC_ENV" APP_DOMAIN)"

PROJECT="vpnmarket_$(domain_slug "$DST_DOMAIN")"
DST_ID="${INSTANCE_ID_OVERRIDE:-$PROJECT}"

[ -n "$SRC_ID" ] || err "APP_INSTANCE_ID مبدأ خالی است"
[ "$SRC_ID" != "$DST_ID" ] || err "مبدأ و مقصد نباید همان APP_INSTANCE_ID باشند: $SRC_ID"

docker ps --format '{{.Names}}' | grep -qx "$MYSQL_C" || err "MySQL در حال اجرا نیست: $MYSQL_C"

if [ "$SKIP_CONFIRM" != 1 ]; then
  warn "مبدأ:  $SRC_DOMAIN ($SRC_ID)"
  warn "مقصد: $DST_DOMAIN ($DST_ID)"
  warn "دیتابیس مشترک — دادهٔ aof دست نخورده می‌ماند."
  read -r -p "ادامه؟ [y/N] " ans
  case "$ans" in y|Y|yes|YES) ;; *) err "لغو شد" ;; esac
fi

mkdir -p "$TEMPLATE_DIR"

if [ -z "$TEMPLATE" ]; then
  TEMPLATE="$TEMPLATE_DIR/lab-from-$(domain_slug "$SRC_DOMAIN")-$(date +%Y%m%d-%H%M).sql"
fi

if [ "$SKIP_EXPORT" != 1 ] || [ ! -f "$TEMPLATE" ]; then
  info "بکاپ tenant مبدأ → $TEMPLATE"
  "$EXPORT" "$SRC_ENV" "$TEMPLATE" --mysql-container "$MYSQL_C"
else
  info "استفاده از قالب موجود: $TEMPLATE"
fi

create_lab_env_from_source() {
  local src_env="$1" dest_dir="$2" dest_domain="$3" dest_project="$4" dest_instance="$5"
  mkdir -p "$dest_dir"
  cp "$ROOT/deploy/instances/_template/docker-compose.yml" "$dest_dir/docker-compose.yml"

  local env_abs
  env_abs="$(cd "$dest_dir" && pwd)/.env"

  info "ساخت .env lab از مبدأ (همان APP_KEY و XMPlus برای رمزهای رمزنگاری‌شده)"
  grep -v -E '^(COMPOSE_PROJECT_NAME|ENV_FILE|INSTANCE_ENV_FILE|APP_INSTANCE_ID|APP_NAME|APP_URL|ASSET_URL|APP_DOMAIN|TRAEFIK_ROUTER_NAME)=' "$src_env" >"$env_abs"

  {
    echo "COMPOSE_PROJECT_NAME=${dest_project}"
    echo "ENV_FILE=${env_abs}"
    echo "INSTANCE_ENV_FILE=${env_abs}"
    echo "APP_INSTANCE_ID=${dest_instance}"
    echo "APP_NAME=\"LAB (${SRC_DOMAIN})\""
    echo "APP_URL=https://${dest_domain}"
    echo "ASSET_URL=https://${dest_domain}"
    echo "APP_DOMAIN=${dest_domain}"
    echo "TRAEFIK_ROUTER_NAME=${dest_project}"
  } >>"$env_abs"

  chmod 600 "$env_abs"
  write_instance_mount_fragment "$dest_dir" "$env_abs" bot || err "mount fragment"
}

if [ ! -f "$DST_DIR/.env" ]; then
  create_lab_env_from_source "$SRC_ENV" "$DST_DIR" "$DST_DOMAIN" "$PROJECT" "$DST_ID"
  ok "پوشه lab ساخته شد: $DST_DIR"
else
  info "از قبل وجود دارد: $DST_DIR/.env"
  existing_id="$(load_env_var "$DST_DIR/.env" APP_INSTANCE_ID)"
  if [ "$existing_id" != "$DST_ID" ]; then
    warn "APP_INSTANCE_ID فعلی lab: $existing_id (اسکریپت انتظار $DST_ID دارد)"
  fi
fi

if [ "$SKIP_UP" != 1 ]; then
  info "بالا آوردن کانتینر lab (fix-instance-db)…"
  "$FIX_DB" "$DST_DOMAIN" bot
else
  warn "رد شد: --skip-up — خودتان کانتینر را up کنید"
fi

if [ -f "$DST_DIR/.env" ] && [ "$FORCE_REIMPORT" != 1 ]; then
  DST_ENV="$(resolve_env_file "$DST_DIR/.env")"
  # shellcheck disable=SC1090
  set -a && source "$DST_ENV" && set +a
  sql_dst_id="$(printf '%s' "$DST_ID" | sed "s/'/''/g")"
  user_count="$(docker exec -e MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" "$MYSQL_C" mysql -uroot -N -e \
    "SELECT COUNT(*) FROM \`${DB_DATABASE}\`.users WHERE instance_id='${sql_dst_id}'" 2>/dev/null || echo 0)"
  if [ "${user_count:-0}" -gt 0 ]; then
    warn "روی lab قبلاً کاربر با instance_id=$DST_ID هست."
    warn "برای import دوباره: --force-reimport"
    if [ "$SKIP_CONFIRM" = 1 ]; then
      err "بدون --force-reimport متوقف شد"
    fi
    read -r -p "باز هم import شود؟ [y/N] " ans2
    case "$ans2" in y|Y|yes|YES) FORCE_REIMPORT=1 ;; *) err "لغو شد" ;; esac
  fi
fi

IMPORT_ARGS=( "$TEMPLATE" "$DST_DOMAIN" --mysql-container "$MYSQL_C" --skip-confirm )
[ "$FORCE_REIMPORT" = 1 ] && true

info "import دادهٔ aof به lab…"
"$IMPORT_BOOT" "${IMPORT_ARGS[@]}"

if [ "$SKIP_REBUILD" != 1 ]; then
  info "rebuild image/web lab…"
  "$REBUILD" "$DST_DOMAIN" --no-build 2>/dev/null || "$REBUILD" "$DST_DOMAIN"
fi

ok "lab آماده است"
echo ""
echo "  دامنه:     https://${DST_DOMAIN}"
echo "  پنل:       https://${DST_DOMAIN}/admin"
echo "  instance:  ${DST_ID}"
echo "  SQL قالب:  ${TEMPLATE}"
echo ""
warn "حتماً ربات تلگرام جدا برای lab بسازید (توکن aof را reuse نکنید):"
echo "  docker exec ${PROJECT}-web-1 php artisan vpnmarket:provision-instance --token=NEW_TOKEN --no-interaction"
echo "  docker exec ${PROJECT}-web-1 php artisan telegram:set-webhook --no-interaction"
echo ""
warn "XMPlus همان پنل مبدأ است — تمدید/خرید در lab روی همان اکانت‌های کپی‌شده تست می‌شود."
