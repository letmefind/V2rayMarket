#!/usr/bin/env bash
# رمز یکسان در VPNMarket + لیست ایمیل برای تنظیم همان رمز در Admin XMPlus
#
#   ./deploy/bin/xmplus-assign-unified-password.sh bypassnet.bypax.store
#   XMPLUS_UNIFIED_PASSWORD='YourPass123' ./deploy/bin/xmplus-assign-unified-password.sh bypassnet.bypax.store --verify
#
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
INSTANCE_ARG="${1:-}"
shift || true

[ -n "$INSTANCE_ARG" ] || { echo "usage: $0 DOMAIN_OR_INSTANCE_ENV [-- dry-run|verify ...]" >&2; exit 1; }

resolve_dest() {
  local name="$1"
  if [ -f "$name/.env" ]; then
    printf '%s\n' "$(cd "$(dirname "$name")" && pwd)/$(basename "$name")/.env"
    return 0
  fi
  local d="$ROOT/deploy/instances/$name"
  [ -f "$d/.env" ] && printf '%s\n' "$d/.env" && return 0
  return 1
}

ENV_FILE="$(resolve_dest "$INSTANCE_ARG")" || { echo "instance not found: $INSTANCE_ARG" >&2; exit 1; }

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

CTR="${COMPOSE_PROJECT_NAME:?COMPOSE_PROJECT_NAME missing}-web-1"
[ -n "${APP_INSTANCE_ID:-}" ] || { echo "APP_INSTANCE_ID missing in .env" >&2; exit 1; }

EXTRA=()
for a in "$@"; do EXTRA+=("$a"); done

DOCKER_ARGS=(-u www-data)
ARTISAN_ARGS=(--instance="$APP_INSTANCE_ID")

if [ -n "${XMPLUS_UNIFIED_PASSWORD:-}" ]; then
  DOCKER_ARGS+=(-e "XMPLUS_UNIFIED_PASSWORD=${XMPLUS_UNIFIED_PASSWORD}")
  ARTISAN_ARGS+=(--password="${XMPLUS_UNIFIED_PASSWORD}")
fi

exec docker exec "${DOCKER_ARGS[@]}" "$CTR" php artisan xmplus:assign-unified-password \
  "${ARTISAN_ARGS[@]}" \
  "${EXTRA[@]}"
