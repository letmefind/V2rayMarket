#!/usr/bin/env bash
set -euo pipefail

if [ $# -lt 1 ]; then
  echo "Usage: $0 <domain> [project_name]"
  echo "Example: $0 x.com vpnmarket_x"
  exit 1
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
