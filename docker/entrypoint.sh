#!/bin/sh
set -e

cd /var/www/html

rm -f bootstrap/cache/config.php 2>/dev/null || true
php artisan config:clear --no-interaction 2>/dev/null || true

if [ -f .env ]; then
  chmod 640 .env 2>/dev/null || true
  chown www-data:www-data .env 2>/dev/null || true
elif [ -d .env ]; then
  echo "[entrypoint] FATAL: .env is a directory — fix host mount (rm deploy/instances/<domain>/.env dir and recreate file)" >&2
  exit 1
elif [ -f .env.example ] && [ "${APP_ENV:-production}" != "production" ]; then
  echo "[entrypoint] Warning: .env missing; copying .env.example (local only)"
  cp .env.example .env
else
  echo "[entrypoint] FATAL: .env missing — mount deploy/instances/<domain>/.env (never use repo .env.example in production)" >&2
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
  exit 1
}

if [ "${WAIT_FOR_DB:-true}" = "true" ]; then
  wait_for_db
fi

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  php artisan migrate --force --no-interaction || true
fi

php artisan storage:link --force 2>/dev/null || true

# config:cache در Docker .env mount را قفل می‌کند (root/بدون رمز) — استفاده نکنید
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
