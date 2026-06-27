# shellcheck shell=bash
# نمونهٔ چنددامنه: bind مطلق .env به /run/instance.env (append واقعی؛ بدون اتکا به !reset در همان فایل)

write_instance_mount_fragment() {
  local dest_dir="$1"
  local env_abs="$2"
  local mode="${3:-pickup}"

  [ -f "$env_abs" ] || {
    echo "write_instance_mount_fragment: .env نیست: $env_abs" >&2
    return 1
  }
  [ -d "$dest_dir" ] || {
    echo "write_instance_mount_fragment: پوشه نیست: $dest_dir" >&2
    return 1
  }

  local frag="$dest_dir/docker-compose.mount.yml"
  # app_storage باید همراه .env باشد؛ mount فقط .env باعث می‌شود رسید/فایل‌ها با recreate از بین بروند.
  if [ "$mode" = "bot" ]; then
    cat >"$frag" <<EOF
services:
  web:
    volumes:
      - app_storage:/var/www/html/storage
      - ${env_abs}:/run/instance.env:ro
  queue:
    volumes:
      - app_storage:/var/www/html/storage
      - ${env_abs}:/run/instance.env:ro
  scheduler:
    volumes:
      - app_storage:/var/www/html/storage
      - ${env_abs}:/run/instance.env:ro
EOF
  else
    cat >"$frag" <<EOF
services:
  web:
    volumes:
      - app_storage:/var/www/html/storage
      - ${env_abs}:/run/instance.env:ro
EOF
  fi
}
