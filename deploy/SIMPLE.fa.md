# نصب ساده (۳ دستور)

## اولین بار روی سرور خام

```bash
git clone https://github.com/letmefind/V2rayMarket.git VPNMarket
cd VPNMarket
chmod +x deploy/install.sh
./deploy/install.sh
```

اسکریپت می‌پرسد: ایمیل SSL، دامنه pickup (`bale.cyou`)، دامنه ربات، توکن تلگرام، ادمین.

**پیش‌نیاز:** DNS دامنه‌ها → IP سرور؛ پورت 80 و 443 باز.

---

## ربات دوم

```bash
./deploy/install.sh add-bot shop2.example.com
```

---

## اگر DB / .env خراب شد

```bash
./deploy/install.sh fix-db bale.cyou
```

---

## redeploy بدون از دست دادن رسید و فایل‌ها

- **سفارش‌ها و کاربران** در MySQL مشترک (`vpnmarket_shared`) می‌مانند.
- **عکس رسید واریز** در `storage/app/public/receipts` است — باید روی volume دائمی `app_storage` باشد.

قبل از اولین redeploy بعد از به‌روزرسانی، یک‌بار روی سرور:

```bash
cd ~/VPNMarket
git pull
chmod +x deploy/bin/migrate-instance-storage-volume.sh
./deploy/bin/migrate-instance-storage-volume.sh --all-bots
```

بعداً برای deploy کد:

```bash
./deploy/bin/rebuild-instance-web.sh --all-bots
```

**هرگز** `docker compose down -v` نزنید (volume دیتابیس پاک می‌شود).

اگر پنل «صفر فروش» نشان داد، `APP_INSTANCE_ID` در `.env` همان ربات را با دیتابیس چک کنید — اگر عوض شده باشد، سفارش‌ها در DB هستند ولی Filament فیلتر instance اشتباه می‌زند.

---

## ادامه migrate (بعد از git pull)

```bash
cd ~/VPNMarket
git pull
docker exec vpnmarket_bale_cyou-web-1 php artisan migrate --force
```
