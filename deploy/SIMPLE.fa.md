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

## ادامه migrate (بعد از git pull)

```bash
cd ~/VPNMarket
git pull
docker exec vpnmarket_bale_cyou-web-1 php artisan migrate --force
```
