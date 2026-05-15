#!/usr/bin/env bash
# نصب ساده — بدون منوی چندگزینه‌ای
# استفاده: ./deploy/install.sh
#          ./deploy/install.sh add-bot shop.example.com
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
chmod +x deploy/bin/vpnmarket-provision.sh deploy/bin/fix-instance-db.sh 2>/dev/null || true
[ -x provision ] || true

case "${1:-}" in
  add-bot)
    [ -n "${2:-}" ] || { echo "Usage: $0 add-bot <domain>"; exit 1; }
    exec "$ROOT/deploy/bin/vpnmarket-provision.sh" bot "$2"
    ;;
  fix|fix-db)
    DOMAIN="${2:-bale.cyou}"
    exec "$ROOT/deploy/bin/fix-instance-db.sh" "$DOMAIN"
    ;;
  status)
    exec "$ROOT/deploy/bin/vpnmarket-provision.sh" status
    ;;
  ""|install)
    exec "$ROOT/deploy/bin/vpnmarket-provision.sh" auto
    ;;
  *)
    echo "Usage: $0 [install|add-bot <domain>|fix-db [pickup-domain]|status]"
    exit 1
    ;;
esac
