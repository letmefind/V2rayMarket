#!/bin/sh
set -e

cd /var/www/html

rm -f bootstrap/cache/config.php 2>/dev/null || true
php artisan config:clear --no-interaction 2>/dev/null || true
php artisan view:clear --no-interaction 2>/dev/null || true
# storage روی volume است؛ اگر view:clear خطا بدهد یا نیمه‌کاره بماند، Blade قدیمی سرو می‌شود.
rm -rf storage/framework/views/* 2>/dev/null || true

if [ -f /run/instance.env ]; then
  cp /run/instance.env /var/www/html/.env
  chmod 640 /var/www/html/.env
  chown www-data:www-data /var/www/html/.env
  echo "[entrypoint] Loaded .env from /run/instance.env"
elif [ -f .env ]; then
  chmod 640 .env 2>/dev/null || true
  chown www-data:www-data .env 2>/dev/null || true
elif [ -d .env ]; then
  echo "[entrypoint] FATAL: .env is a directory — rm deploy/instances/<domain>/.env on host" >&2
  exit 1
elif [ -f .env.example ] && [ "${APP_ENV:-production}" != "production" ]; then
  echo "[entrypoint] Warning: .env missing; copying .env.example (local only)"
  cp .env.example .env
else
  echo "[entrypoint] FATAL: no /run/instance.env and no .env — check Docker volume mount" >&2
  exit 1
fi

wait_for_db() {
  if [ "${DB_CONNECTION:-mysql}" != "mysql" ]; then
    return 0
  fi
  echo "[entrypoint] Waiting for MySQL..."
  i=0
  while [ "$i" -lt 60 ]; do
    if php artisan db:show --no-interaction >/dev/null 2>&1; then
      echo "[entrypoint] MySQL is up."
      return 0
    fi
    i=$((i + 1))
    sleep 2
  done
  echo "[entrypoint] MySQL not reachable after timeout." >&2
  echo "[entrypoint] DB_HOST=${DB_HOST:-?} DB_DATABASE=${DB_DATABASE:-?} DB_USERNAME=${DB_USERNAME:-?}" >&2
  php artisan db:show --no-interaction 2>&1 | tail -5 >&2 || true
  exit 1
}

if [ "${WAIT_FOR_DB:-true}" = "true" ]; then
  wait_for_db
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force --no-interaction || true
fi

php artisan storage:link --force 2>/dev/null || true

if [ "${LARAVEL_ROUTE_CACHE:-false}" = "true" ]; then
  php artisan route:cache --no-interaction 2>/dev/null || true
fi
if [ "${LARAVEL_VIEW_CACHE:-false}" = "true" ]; then
  php artisan view:cache --no-interaction 2>/dev/null || true
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

if [ "$1" != "/usr/bin/supervisord" ]; then
  exec gosu www-data "$@"
fi

exec "$@"
