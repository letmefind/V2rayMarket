#!/usr/bin/env bash
# یک Traefik مشترک با Docker API جدید روی 80/443 — قبلش هر traefik دیگر روی همین پورت را متوقف کنید.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TRAEFIK_DIR="$ROOT/deploy/traefik"

echo "→ انتشار پورت ۴۴۳ (قبل از بالا آوردن Traefik مشترک):"
docker ps --format '{{.Names}}\t{{.Ports}}' | grep ':443->' || echo "(هیچ)"

echo "→ شبکهٔ proxy"
docker network create proxy 2>/dev/null || true

echo "→ بالا آوردن Traefik مشترک از $TRAEFIK_DIR"
(cd "$TRAEFIK_DIR" && docker compose pull && docker compose up -d --force-recreate)

sleep 2
docker ps --filter "label=vpnmarket.shared_traefik=true" --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}\t{{.Ports}}'

echo ""
echo "✓ اگر هنوز خطای docker provider در لاگ بود: ایمیج را با این اسکریپت به‌روز کردید؛ مطمئن شوید فقط همین سرویس روی 443 است."
echo "  لاگ: docker logs \"\$(docker ps -qf label=vpnmarket.shared_traefik=true)\" --tail 30"
