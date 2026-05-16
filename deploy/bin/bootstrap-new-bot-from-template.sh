#!/usr/bin/env bash
# ربات جدید را با دادهٔ اولیه از فایل قالب (خروجی export-instance-template.sh) پر می‌کند.
#
# استفاده:
#   ./deploy/bin/bootstrap-new-bot-from-template.sh /root/templates/robot-initial.sql newbot.bypax.store
#   ./deploy/bin/bootstrap-new-bot-from-template.sh TEMPLATE.sql newbot.bypax.store --mysql-container vpnmarket_shared_mysql
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
IMPORT="$ROOT/deploy/bin/import-legacy-sql-to-instance.sh"

[ $# -ge 2 ] || {
  echo "Usage: bootstrap-new-bot-from-template.sh TEMPLATE.sql TARGET_DOMAIN [import options...]" >&2
  exit 1
}

TEMPLATE="$1"
TARGET="$2"
shift 2

[ -f "$TEMPLATE" ] || { echo "فایل قالب نیست: $TEMPLATE" >&2; exit 1; }
[ -x "$IMPORT" ] || { echo "نیست: $IMPORT — git pull" >&2; exit 1; }

USE_SKIP_SETTINGS=1
for a in "$@"; do [ "$a" = "--skip-settings" ] && USE_SKIP_SETTINGS=0; done

ARGS=( "$TARGET" "$TEMPLATE" --skip-telegram-settings --skip-confirm "$@" )
[ "$USE_SKIP_SETTINGS" = 1 ] && ARGS=( --skip-settings "${ARGS[@]}" )

exec "$IMPORT" "${ARGS[@]}"
