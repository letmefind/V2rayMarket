# shellcheck shell=bash
# مشترک: mount مطلق .env نمونه در docker-compose (بدون interpolate متغیر در bind path)
patch_instance_compose_env_mount() {
  local compose_file="$1"
  local env_abs="$2"

  [ -f "$env_abs" ] || { echo "patch_instance_compose_env_mount: .env نیست: $env_abs" >&2; return 1; }
  [ -f "$compose_file" ] || { echo "patch_instance_compose_env_mount: compose نیست: $compose_file" >&2; return 1; }

  # با «اسلش بعد از -» در الگوی /.../ delimiter awk زود تمام می‌شود — از regex رشته‌ای استفاده می‌کنیم
  awk -v env="$env_abs" '
    index($0, ":/run/instance.env:ro") > 0 && match($0, "^[[:space:]]+-[[:space:]]+") {
      prefix = substr($0, 1, RLENGTH)
      print prefix env ":/run/instance.env:ro"
      next
    }
    { print }
  ' "$compose_file" >"${compose_file}.tmp" && mv "${compose_file}.tmp" "$compose_file"

  if ! grep -qF "${env_abs}:/run/instance.env:ro" "$compose_file"; then
    echo "patch_instance_compose_env_mount: mount در $compose_file پیدا نشد" >&2
    return 1
  fi
}

compose_config_has_env_mount() {
  local env_abs="$1"
  shift
  docker compose "$@" config 2>/dev/null | grep -qF "$env_abs"
}
