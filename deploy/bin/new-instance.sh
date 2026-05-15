#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
if [ $# -lt 1 ]; then
  echo "Usage: $0 <domain>"
  echo ""
  echo "برای نصب کامل بدون مرحلهٔ دستی از اسکریپت تعاملی استفاده کنید:"
  echo "  $ROOT/provision"
  echo "  # یا: $ROOT/deploy/bin/vpnmarket-provision.sh"
  exit 1
fi

if [ "${VPNMARKET_LEGACY_NEW_INSTANCE:-}" != "1" ]; then
  echo "→ برای نصب خودکار (Traefik، DB، webhook، ادمین) اجرا کنید:"
  echo "  $ROOT/provision"
  echo "  (گزینه ۲ — افزودن ربات جدید)"
  echo ""
  echo "برای همان رفتار قدیمی فقط کپی قالب: VPNMARKET_LEGACY_NEW_INSTANCE=1 $0 $*"
  exit 0
fi

DOMAIN="$1"
PROJECT="${2:-vpnmarket_${DOMAIN//./_}}"
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DEST="$ROOT/deploy/instances/$DOMAIN"

mkdir -p "$DEST"
if [ -f "$DEST/.env" ]; then
  echo "Already exists: $DEST/.env"
  exit 1
fi

cp "$ROOT/deploy/instances/_template/.env.example" "$DEST/.env"
cp "$ROOT/deploy/instances/_template/docker-compose.yml" "$DEST/docker-compose.yml"

sed -i.bak \
  -e "s|example.com|$DOMAIN|g" \
  -e "s|vpnmarket_example|$PROJECT|g" \
  -e "s|https://example.com|https://$DOMAIN|g" \
  "$DEST/.env"
rm -f "$DEST/.env.bak"

echo "Created instance: $DEST"
echo ""
echo "Next:"
echo "  1. Edit $DEST/.env (passwords, APP_KEY)"
echo "  2. docker network create proxy  # if Traefik not running yet"
echo "  3. cd $DEST && docker compose -f ../../../docker-compose.yml -f ../../../docker-compose.traefik.yml -f docker-compose.yml --env-file .env up -d --build"
