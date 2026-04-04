#!/bin/bash

# ==================================================================================
# === اسکریپت حذف کامل V2rayMarket (مسیر پیش‌فرض: /var/www/vpnmarket) ===
# === منبع اصلی: https://github.com/arvinvahed/VPNMarket ===
# ==================================================================================

set -e

# --- تعریف رنگ‌ها ---
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'
PROJECT_PATH="/var/www/vpnmarket"

echo -e "${YELLOW}--- شروع فرآیند حذف کامل پروژه V2rayMarket ---${NC}"
echo -e "${RED}⚠️ هشدار: این عملیات غیرقابل بازگشت است و تمام فایل‌ها و دیتابیس پروژه را حذف می‌کند.${NC}"
echo

# --- دریافت اطلاعات لازم برای حذف ---
read -p "🌐 لطفا دامنه سایت را برای حذف گواهی SSL وارد کنید (مثال: market.example.com): " DOMAIN
read -p "🗃 نام دیتابیسی که می‌خواهید حذف شود را وارد کنید: " DB_NAME
read -p "👤 نام کاربری دیتابیسی که می‌خواهید حذف شود را وارد کنید: " DB_USER
echo

read -p "آیا از حذف کامل پروژه، دیتابیس و کانفیگ‌های مربوطه اطمینان دارید؟ (y/n): " CONFIRMATION
if [[ "$CONFIRMATION" != "y" && "$CONFIRMATION" != "Y" ]]; then
    echo -e "${YELLOW}عملیات لغو شد.${NC}"
    exit 0
fi

# --- مرحله ۱: توقف سرویس‌ها ---
echo -e "${YELLOW} M 1/5: در حال توقف سرویس‌های Nginx و Supervisor...${NC}"
sudo supervisorctl stop vpnmarket-worker:* || echo "Worker already stopped or not found."
sudo systemctl stop nginx

# --- مرحله ۲: حذف کانفیگ‌های Nginx و Supervisor ---
echo -e "${YELLOW} M 2/5: در حال حذف فایل‌های کانفیگ...${NC}"
sudo rm -f /etc/nginx/sites-available/vpnmarket
sudo rm -f /etc/nginx/sites-enabled/vpnmarket
sudo rm -f /etc/supervisor/conf.d/vpnmarket-worker.conf

echo "بارگذاری مجدد سرویس‌ها برای اعمال تغییرات..."
sudo supervisorctl reread
sudo supervisorctl update
sudo systemctl reload nginx

# --- مرحله ۳: حذف فایل‌های پروژه ---
echo -e "${YELLOW} M 3/5: در حال حذف کامل پوشه پروژه از مسیر $PROJECT_PATH...${NC}"
if [ -d "$PROJECT_PATH" ]; then
    sudo rm -rf "$PROJECT_PATH"
    echo -e "${GREEN}پوشه پروژه با موفقیت حذف شد.${NC}"
else
    echo -e "${YELLOW}پوشه پروژه یافت نشد (احتمالا قبلاً حذف شده است).${NC}"
fi

# --- مرحله ۴: حذف دیتابیس و کاربر دیتابیس ---
echo -e "${YELLOW} M 4/5: در حال حذف دیتابیس و کاربر مربوطه...${NC}"
sudo mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`;"
sudo mysql -e "DROP USER IF EXISTS '$DB_USER'@'localhost';"
sudo mysql -e "FLUSH PRIVILEGES;"
echo -e "${GREEN}دیتابیس و کاربر با موفقیت حذف شدند.${NC}"

# --- مرحله ۵: حذف گواهی SSL ---
read -p "آیا گواهی SSL مربوط به دامنه $DOMAIN نیز حذف شود؟ (y/n): " DELETE_SSL
if [[ "$DELETE_SSL" == "y" || "$DELETE_SSL" == "Y" ]]; then
    echo -e "${YELLOW} M 5/5: در حال حذف گواهی SSL...${NC}"
    sudo certbot delete --cert-name $DOMAIN --non-interactive || echo "گواهی SSL یافت نشد یا در حذف آن مشکلی پیش آمد."
fi

# --- پیام نهایی ---
sudo systemctl start nginx # ری‌استارت Nginx برای اطمینان
echo
echo -e "${GREEN}=====================================================${NC}"
echo -e "${GREEN}✅ فرآیند حذف کامل با موفقیت انجام شد.${NC}"
echo -e "سرور شما اکنون برای نصب مجدد آماده است."
echo -e "${GREEN}=====================================================${NC}"
```

---
### **مرحله ۲: نصب مجدد و تمیز**

حالا که سرور شما کاملاً تمیز شده است، به سادگی می‌توانید با اجرای همان دستور اولیه، پروژه را از نو نصب کنید:

```bash
wget -O install.sh https://raw.githubusercontent.com/letmefind/V2rayMarket/main/install.sh && sudo bash install.sh
