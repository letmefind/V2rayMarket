# اجرای Docker — چند ربات روی یک سرور

هر **نمونه** = یک ربات تلگرام + یک دامنه + دیتابیس و Redis جدا. همه از پورت **443** با **Traefik** (یک reverse proxy مشترک).

## معماری

```text
Internet :443
    → Traefik (یک‌بار روی سرور)
        → نمونه x.com (web + mysql + redis + queue + scheduler)
        → نمونه y.com (web + mysql + redis + queue + scheduler)
```

## پیش‌نیاز سرور

- Docker Engine + Compose v2
- DNS هر دامنه → IP سرور

## ۱) Traefik (فقط یک‌بار)

```bash
cd deploy/traefik
cp .env.example .env   # اگر اضافه کردید؛ یا export ACME_EMAIL=you@mail.com
export ACME_EMAIL=admin@example.com
chmod 600 acme.json
docker compose up -d
docker network ls | grep proxy   # شبکه proxy ساخته می‌شود
```

## ۲) ساخت نمونهٔ جدید (مثلاً x.com)

```bash
chmod +x deploy/bin/new-instance.sh
./deploy/bin/new-instance.sh x.com vpnmarket_x
```

فایل `deploy/instances/x.com/.env` را ویرایش کنید:

- `APP_KEY` → `php artisan key:generate --show` روی ماشین توسعه
- `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`
- `APP_URL=https://x.com`

سپس:

```bash
cd deploy/instances/x.com
docker compose \
  -f ../../../docker-compose.yml \
  -f ../../../docker-compose.traefik.yml \
  -f docker-compose.yml \
  --env-file .env \
  up -d --build
```

وب‌هوک تلگرام: `https://x.com/webhooks/telegram`  
پنل ادمین: `https://x.com/admin` (مسیر Filament شما)

## ۳) نمونهٔ دوم (y.com)

```bash
./deploy/bin/new-instance.sh y.com vpnmarket_y
# ویرایش deploy/instances/y.com/.env
cd deploy/instances/y.com
docker compose -f ../../../docker-compose.yml -f ../../../docker-compose.traefik.yml -f docker-compose.yml --env-file .env up -d --build
```

## تست محلی (بدون Traefik)

```bash
cp .env.example .env
# DB_PASSWORD و MYSQL_ROOT_PASSWORD را پر کنید
docker compose -f docker-compose.yml -f docker-compose.local.yml up -d --build
# http://127.0.0.1:8080
```

## سرویس‌ها

| سرویس | نقش |
|--------|-----|
| web | nginx + PHP-FPM + Laravel |
| queue | `queue:work redis` |
| scheduler | `schedule:work` |
| mysql | دیتابیس اختصاصی نمونه |
| redis | صف و کش |

## به‌روزرسانی کد

```bash
cd deploy/instances/x.com
docker compose -f ../../../docker-compose.yml -f ../../../docker-compose.traefik.yml -f docker-compose.yml --env-file .env up -d --build
docker compose ... exec web php artisan migrate --force
```

## نکات

- هر نمونه **حجم و `.env` جدا** دارد؛ توکن ربات و تنظیمات در DB همان نمونه است.
- پورت **443** فقط روی Traefik باز باشد؛ سرویس‌های نمونه پورت host نمی‌گیرند.
- `storage` و `mysql_data` در volume داکر می‌مانند.
