# V2rayMarket (نسخهٔ کاستوم)

پنل مدیریت و فروش سرویس VPN مبتنی بر **Marzban** و **X-UI (Sanaei)** — ساخته‌شده با **Laravel** و **Filament**.

---

## منبع اصلی پروژه (الزامی)

این مخزن **بر پایهٔ پروژهٔ متن‌باز VPNMarket** توسعه داده شده است. استفاده، مطالعه یا مشارکت در این کد بدون آگاهی از منبع اصلی کامل نیست.

| | لینک |
|---|------|
| **مخزن اصلی (upstream)** | [**github.com/arvinvahed/VPNMarket**](https://github.com/arvinvahed/VPNMarket) |
| **این نسخهٔ کاستوم** | [**github.com/letmefind/V2rayMarket**](https://github.com/letmefind/V2rayMarket) |

تغییرات متعددی نسبت به خط اصلی اعمال شده است (به‌روزرسانی پشته، ماژول‌ها، امکانات ادمین و رفتار سیستم). با این حال **ریشهٔ ایده، معماری اولیه و بخش عمدهٔ پایه از VPNMarket است** و باید در هر بازنشر، مستند یا مشتق دیگر به مخزن بالا ارجاع داده شود.

---

## تفاوت این مخزن با upstream (خلاصه)

- به‌روزرسانی پشته: **Laravel 12**، **PHP 8.3+**، **Filament 3.2**، ابزارهای فرانت‌اند جدیدتر (مثلاً Vite 7).
- معماری **ماژولار** (`nwidart/laravel-modules`) با ماژول‌هایی از جمله:
  - **TelegramBot** — ربات و وب‌هوک
  - **Ticketing** — تیکت و پشتیبانی
  - **Blog** — وبلاگ (دسته و پست)
  - **Referral** — ارجاع و معرفی
- پنل ادمین و منابع اصلی اپلیکیشن: کاربران، سفارش‌ها، پلن‌ها، اینباندها، کدهای تخفیف، پخش تلگرام و غیره.
- پشتیبانی از چند تم در بخش فرانت (مثلاً dragon، cyberpunk، arcane، rocket) و بومی‌سازی فارسی در `resources/lang`.

جزئیات دقیق تغییرات را می‌توانید با مقایسهٔ تاریخچهٔ گیت با upstream دنبال کنید.

---

## پیش‌نیازها

- PHP **^8.3** با اکستنشن‌های معمول Laravel
- Composer 2
- Node.js و npm (برای بیلد فرانت)
- پایگاه داده: معمولاً **MySQL** / MariaDB
- وب‌سرور: Nginx یا Apache (تنظیم `public` به‌عنوان document root)

---

## نصب سریع (کلون این مخزن)

```bash
git clone https://github.com/letmefind/V2rayMarket.git
cd V2rayMarket
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed   # در صورت وجود seeder مناسب
npm install && npm run build
php artisan storage:link
```

سپس متغیرهای `.env` (دیتابیس، URL اپ، تنظیمات پنل Marzban/X-UI، تلگرام و درگاه‌ها) را مطابق سرور خود پر کنید.

### نصب یک‌خطی (Ubuntu — همان اسکریپت رسمی در این مخزن)

```bash
wget -O install.sh https://raw.githubusercontent.com/letmefind/V2rayMarket/main/install.sh && sudo bash install.sh
```

اسکریپت به‌طور پیش‌فرض همین مخزن (`letmefind/V2rayMarket`) را کلون می‌کند. برای نصب از **upstream**، مقدار `GITHUB_REPO` در `install.sh` را به `https://github.com/arvinvahed/VPNMarket.git` تغییر دهید.

---

## آپدیت (پس از استقرار با Git)

```bash
cd /path/to/V2rayMarket
git pull origin main
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize:clear
```

در صورت استفاده از `update.sh` روی سرور، ابتدا مطمئن شوید مخزن ریموت همان همین fork است.

---

## لایسنس

طبق `composer.json` و سنت پروژهٔ upstream، مجوز **MIT** برای پایه در نظر گرفته می‌شود. برای متن دقیق مجوز، مخزن [**arvinvahed/VPNMarket**](https://github.com/arvinvahed/VPNMarket) را ببینید.

---

## جمع‌بندی ارجاع

- **منبع اصلی و اعتبار پروژهٔ پایه:** [VPNMarket — arvinvahed](https://github.com/arvinvahed/VPNMarket)  
- **نسخهٔ کاستوم حاضر:** [V2rayMarket — letmefind](https://github.com/letmefind/V2rayMarket)
