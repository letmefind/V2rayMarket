# shellcheck shell=bash
# مشترک: mount مطلق .env نمونه در docker-compose (بدون وابستگی به interpolate compose)
patch_instance_compose_env_mount() {
  local compose_file="$1"
  local env_abs="$2"

  [ -f "$env_abs" ] || { echo "patch_instance_compose_env_mount: .env نیست: $env_abs" >&2; return 1; }
  [ -f "$compose_file" ] || { echo "patch_instance_compose_env_mount: compose نیست: $compose_file" >&2; return 1; }

  sed -i.bak \
    -e 's|\${ENV_FILE:-\${INSTANCE_ENV_FILE}}|'"$env_abs"'|g' \
    -e 's|\${ENV_FILE}|'"$env_abs"'|g' \
    -e 's|\${INSTANCE_ENV_FILE}|'"$env_abs"'|g' \
    -e 's|\./\.env:'"$env_abs"':|g' \
    "$compose_file"
  rm -f "${compose_file}.bak"

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
