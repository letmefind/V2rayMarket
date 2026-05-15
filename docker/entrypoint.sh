#!/bin/sh
set -e

cd /var/www/html

php artisan config:clear --no-interaction 2>/dev/null || true

if [ -f .env ]; then
  :
elif [ -f .env.example ]; then
  echo "[entrypoint] Warning: .env missing; copying .env.example (set real values via mount or env_file)"
  cp .env.example .env
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

if [ "${APP_ENV:-production}" = "production" ]; then
  php artisan config:cache --no-interaction 2>/dev/null || true
  php artisan route:cache --no-interaction 2>/dev/null || true
  php artisan view:cache --no-interaction 2>/dev/null || true
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

if [ "$1" != "/usr/bin/supervisord" ]; then
  exec gosu www-data "$@"
fi

exec "$@"
