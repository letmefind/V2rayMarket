# اجرای Docker — چند ربات روی یک سرور

هر **نمونه ربات** = یک دامنه (x.com) + `APP_INSTANCE_ID` جدا.  
**یک MySQL مشترک** — داده‌ها با ستون `instance_id` قاطی نمی‌شوند.  
**یک وب مشترک** (مثلاً `bale.cyou`) — فقط ورود **کد ۵ رقمی**؛ کد از همهٔ ربات‌ها در جدول `service_shares` مشترک است.

## نصب خودکار (پیشنهادی — بدون کار دستی)

روی سرور لینوکس، از ریشهٔ مخزن:

```bash
chmod +x provision deploy/bin/vpnmarket-provision.sh
./provision
```

منوی تعاملی:

| گزینه | کار |
|--------|-----|
| **1** | اولین بار: Traefik + MySQL/Redis مشترک + وب pickup + یک ربات |
| **2** | هر بار بعد: فقط ربات جدید (دامنه، توکن BotFather، ادمین Filament) |
| **3** | فقط وب کد ۵ رقمی |
| **4** | فقط زیرساخت (Traefik + DB) |
| **5** | وضعیت کانتینرها و نمونه‌ها |

اسکریپت این کارها را خودکار انجام می‌دهد: ساخت `.env`، `docker compose up --build`، migrate، seed پیام‌های ربات، ذخیره توکن در DB، ساخت کاربر ادمین، `telegram:set-webhook`. تنظیمات خوشه در `deploy/.provision/cluster.env` (در git نیست) ذخیره می‌شود.

**پیش‌نیاز:** DNS هر دامنه به IP سرور اشاره کند؛ پورت‌های 80 و 443 باز باشند.

## معماری (پیشنهادی)

```text
Internet :443
    → Traefik
        → bale.cyou     (APP_SHARE_PICKUP_ONLY=true — فقط صفحه کد ۵ رقمی)
        → x.com         (ربات A — APP_INSTANCE_ID=vpnmarket_x)
        → y.com         (ربات B — APP_INSTANCE_ID=vpnmarket_y)
              ↘
         MySQL + Redis مشترک (docker-compose.shared-db.yml)
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

**هنوز مرورگر «TRAEFIK DEFAULT CERT» / گواهی نامعتبر؟** معمولاً Let's Encrypt روی پورت ۸۰ چالش HTTP-01 را کامل نکرده (مثلاً روتر ریدایرکت به HTTPS با اولویت بالاتر از چالش داخلی Traefik بوده). در `docker-compose.traefik.yml` برای روتر `*-http` اولویت پایین (`priority=1`) تنظیم شده تا ACME برنده شود. روی سرور بعد از `git pull` سرویس pickup (و در صورت نیاز Traefik) را یک‌بار `docker compose up -d --force-recreate` کنید؛ `docker logs` روی Traefik مشترک (لیبل `vpnmarket.shared_traefik=true`) را برای خطاهای `acme` ببینید. اگر قبلاً چند بار شکست خورده، تا رفع **rate limit** صبر کنید یا `acme.json` را فقط در صورت خرابی/تست پاک و دوباره `chmod 600` کنید.

## ۲) MySQL و Redis مشترک (یک‌بار)

```bash
export MYSQL_ROOT_PASSWORD='...'
docker compose -f docker-compose.shared-db.yml up -d
docker network ls | grep vpnmarket_shared_data
```

روی MySQL یک دیتابیس بسازید (مثلاً `vpnmarket_shared`) و کاربر `vpnmarket` با دسترسی کامل.

اولین نمونه بعد از `up` باید `php artisan migrate --force` را اجرا کند (در entrypoint خودکار است).

## ۳) وب مشترک کد ۵ رقمی (مثلاً bale.cyou)

**پیشنهادی (هماهنگ با اسکریپت):** از **ریشهٔ مخزن** cluster را داشته باشید (`deploy/.provision/cluster.env`)، بعد:

```bash
cd /path/to/VPNMarket
chmod +x deploy/bin/fix-instance-db.sh
./deploy/bin/fix-instance-db.sh bale.cyou pickup
```

این اسکریپت `.env`، **`docker-compose.mount.yml`** (کپی `.env` به `/run/instance.env` داخل کانتینر)، `--project-directory` و `-p` را درست می‌کند؛ بدون mount، entrypoint با پیام `FATAL: no /run/instance.env and no .env` خارج می‌شود و کانتینر **مدام Restarting** می‌ماند. همچنین **`docker-compose.web-rebuild.yml`** را به زنجیره اضافه می‌کند تا `up --build` واقعاً از `Dockerfile` بیلد بگیرد (`build-root` به‌تنهایی بیلد را حذف می‌کند).

**دستی:** اگر بدون اسکریپت بالا می‌آورید، حتماً یک‌بار mount بسازید و در دستور `compose` بگذارید:

```bash
cd deploy/instances/bale.cyou
source ../../../deploy/bin/lib/instance-compose.sh
write_instance_mount_fragment "$(pwd)" "$(pwd)/.env" pickup

cd ../../..   # برگرد به ریشهٔ VPNMarket
export INSTANCE_ENV_FILE="$(pwd)/deploy/instances/bale.cyou/.env"
set -a && source "$INSTANCE_ENV_FILE" && set +a

docker compose --project-directory "$(pwd)" \
  -f docker-compose.yml \
  -f deploy/docker-compose.no-local-db.yml \
  -f deploy/docker-compose.instance-env.yml \
  -f deploy/docker-compose.build-root.yml \
  -f deploy/docker-compose.web-rebuild.yml \
  -f deploy/docker-compose.pickup-only.yml \
  -f docker-compose.traefik.yml \
  -f deploy/instances/bale.cyou/docker-compose.yml \
  -f deploy/instances/bale.cyou/docker-compose.mount.yml \
  --env-file "$INSTANCE_ENV_FILE" \
  -p "${COMPOSE_PROJECT_NAME:-vpnmarket_bale_cyou}" \
  up -d --build --force-recreate
```

قبل از آن (اگر `.env` ندارید):

```bash
mkdir -p deploy/instances/bale.cyou
cp deploy/instances/_template.pickup/.env.example deploy/instances/bale.cyou/.env
cp deploy/instances/_template.pickup/docker-compose.yml deploy/instances/bale.cyou/
# ویرایش .env — APP_URL ، APP_DOMAIN ، DB_* مشترک
```

در `.env` هر **ربات** مقدار `IRAN_SERVICE_SHARE_URL` / `services.iran_share` را روی `https://bale.cyou` بگذارید (همان دامنهٔ pickup).

## محیط Lab — کپی کامل aof برای تست

روی **همان سرور** (MySQL مشترک)، بدون جابه‌جایی دادهٔ aof:

```bash
cd ~/VPNMarket
git pull

# DNS: lab.bypax.store → IP سرور (یا دامنهٔ lab خودتان)

chmod +x deploy/bin/bootstrap-lab-from-instance.sh
./deploy/bin/bootstrap-lab-from-instance.sh aof.bypax.store lab.bypax.store
```

اسکریپت: export دادهٔ aof → ساخت `deploy/instances/lab.bypax.store` (همان `APP_KEY` / XMPlus) → import → rebuild.

بعداً **توکن ربات جدا** و webhook:

```bash
docker exec vpnmarket_lab_bypax_store-web-1 php artisan vpnmarket:provision-instance --token='BOT_TOKEN' --no-interaction
docker exec vpnmarket_lab_bypax_store-web-1 php artisan telegram:set-webhook --no-interaction
```

import دوباره: `--force-reimport` — فقط export قبلی: `--template /root/templates/....sql --skip-export`

## ۴) ساخت نمونهٔ ربات (مثلاً x.com)

```bash
chmod +x deploy/bin/new-instance.sh
./deploy/bin/new-instance.sh x.com vpnmarket_x
```

فایل `deploy/instances/x.com/.env` را ویرایش کنید:

- `APP_KEY` → `php artisan key:generate --show` روی ماشین توسعه
- `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`
- `APP_URL=https://x.com`

سپس از **ریشهٔ مخزن** (مثل `fix-instance-db.sh`): `write_instance_mount_fragment` برای `bot` اجرا کنید و `-f docker-compose.mount.yml` و `--project-directory` و `-p` را به دستور اضافه کنید؛ یا از `./deploy/bin/fix-instance-db.sh x.com bot` بعد از آماده بودن `cluster.env` استفاده کنید.

```bash
cd deploy/instances/x.com
source ../../../deploy/bin/lib/instance-compose.sh
write_instance_mount_fragment "$(pwd)" "$(pwd)/.env" bot
cd ../../..
export INSTANCE_ENV_FILE="$(pwd)/deploy/instances/x.com/.env"
set -a && source "$INSTANCE_ENV_FILE" && set +a

docker compose --project-directory "$(pwd)" \
  -f docker-compose.yml \
  -f deploy/docker-compose.no-local-db.yml \
  -f deploy/docker-compose.instance-env.yml \
  -f deploy/docker-compose.build-root.yml \
  -f deploy/docker-compose.web-rebuild.yml \
  -f deploy/docker-compose.bot-workers.yml \
  -f docker-compose.traefik.yml \
  -f deploy/instances/x.com/docker-compose.yml \
  -f deploy/instances/x.com/docker-compose.mount.yml \
  --env-file "$INSTANCE_ENV_FILE" \
  -p "${COMPOSE_PROJECT_NAME}" \
  up -d --build
```

وب‌هوک تلگرام: `https://x.com/webhooks/telegram`  
پنل ادمین: `https://x.com/admin` (مسیر Filament شما)

## ۵) نمونهٔ دوم ربات (y.com)

```bash
./deploy/bin/new-instance.sh y.com vpnmarket_y
# ویرایش deploy/instances/y.com/.env
cd deploy/instances/y.com
docker compose -f ../../../docker-compose.yml -f ../../../docker-compose.traefik.yml -f docker-compose.yml --env-file .env up -d --build
```

## متغیرهای مهم

| متغیر | نقش |
|--------|-----|
| `APP_INSTANCE_ID` | جداسازی کاربر/سفارش/تنظیمات در DB مشترک |
| `APP_SHARE_PICKUP_ONLY=true` | فقط مسیرهای کد ۵ رقمی (بدون فروشگاه/ادمین) |
| `DB_DATABASE` | نام دیتابیس مشترک (یکسان برای همه) |

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
