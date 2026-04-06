# 📱 سیستم مدیریت پیام‌های ربات تلگرام

## 🎯 هدف

این سیستم به شما امکان می‌دهد تمام متن‌های دکمه‌ها و پیام‌های ربات تلگرام را بدون نیاز به تغییر کد، از طریق پنل مدیریت (Filament) ویرایش کنید.

## 📋 ویژگی‌ها

- ✅ مدیریت آسان پیام‌ها از پنل ادمین
- ✅ دسته‌بندی پیام‌ها (دکمه‌ها، پیام‌ها، خطاها، تاییدها و...)
- ✅ استفاده از متغیرها مثل `{order_id}`, `{amount}`, `{plan_name}`
- ✅ کش خودکار برای بهبود سرعت
- ✅ فعال/غیرفعال سازی پیام‌ها
- ✅ پشتیبانی کامل از Emoji و فرمت‌های تلگرام

## 🚀 نصب و راه‌اندازی

### 1. اجرای Migration

```bash
php artisan migrate
```

### 2. اجرای Seeder (ایجاد پیام‌های پیش‌فرض)

```bash
php artisan db:seed --class=BotMessageSeeder
```

### 3. پاک کردن کش

```bash
php artisan cache:clear
```

## 📚 نحوه استفاده در کد

### روش 1: استفاده مستقیم از Model

```php
use App\Models\BotMessage;

// دریافت متن ساده
$text = BotMessage::get('btn_payment_card', '💳 کارت به کارت');

// دریافت با متغیرها
$text = BotMessage::get('msg_invoice_header', 'پیام پیش‌فرض', [
    'plan_name' => 'پلن 10گیگ',
    'amount' => '100,000',
    'balance' => '50,000'
]);
```

### روش 2: استفاده از Helper

```php
use App\Support\BotMessageHelper as Msg;

// دکمه‌ها
$btnText = Msg::button('btn_payment_card', '💳 کارت به کارت');

// پیام‌ها
$message = Msg::message('msg_invoice_header', 'متن پیش‌فرض', [
    'order_id' => $order->id,
    'amount' => Msg::formatAmount($order->amount),
]);

// خطاها
$error = Msg::error('err_discount_invalid', '❌ کد تخفیف نامعتبر است.');

// تاییدها
$confirm = Msg::confirmation('conf_service_activated', '✅ سرویس فعال شد', [
    'plan_name' => $plan->name
]);

// متغیرهای خودکار
$vars = Msg::orderVariables($order);
$message = Msg::message('msg_invoice_header', 'پیام پیش‌فرض', $vars);
```

### روش 3: استفاده در دکمه‌های Telegram

```php
use Telegram\Bot\Keyboard\Keyboard;
use App\Support\BotMessageHelper as Msg;

$keyboard = Keyboard::make()->inline();

$keyboard->row([
    Keyboard::inlineButton([
        'text' => Msg::button('btn_payment_card', '💳 کارت به کارت'),
        'callback_data' => "pay_card_{$order->id}"
    ])
]);

$keyboard->row([
    Keyboard::inlineButton([
        'text' => Msg::button('btn_payment_online', '🌐 پرداخت آنلاین'),
        'callback_data' => "pay_online_{$order->id}"
    ])
]);
```

## 🔧 مدیریت از پنل ادمین

1. وارد پنل ادمین شوید
2. از منوی سمت راست بخش **"ربات تلگرام"** را پیدا کنید
3. روی **"پیام‌های ربات"** کلیک کنید
4. می‌توانید:
   - ✏️ پیام‌های موجود را ویرایش کنید
   - ➕ پیام جدید اضافه کنید
   - 🔍 بر اساس دسته‌بندی فیلتر کنید
   - ✅/❌ پیام‌ها را فعال/غیرفعال کنید
   - 🗑️ پیام‌های غیرضروری را حذف کنید

### دکمه "پاک کردن کش"

بعد از ویرایش پیام‌ها، حتماً دکمه **"پاک کردن کش"** را در صفحه لیست بزنید تا تغییرات بلافاصله اعمال شوند.

## 📝 متغیرهای رایج

| متغیر | توضیحات | مثال |
|-------|---------|------|
| `{order_id}` | شماره سفارش | 123 |
| `{amount}` | مبلغ (فرمت شده) | 1,000,000 |
| `{plan_name}` | نام پلن | پلن 10 گیگ |
| `{plan_price}` | قیمت پلن | 150,000 |
| `{username}` | نام کاربر | علی |
| `{user_id}` | شناسه کاربر | 456 |
| `{balance}` | موجودی کیف پول | 50,000 |
| `{discount_code}` | کد تخفیف | SUMMER30 |
| `{discount_amount}` | مبلغ تخفیف | 30,000 |
| `{config_link}` | لینک کانفیگ | https://... |
| `{expires_at}` | تاریخ انقضا | 1404/02/15 |

## 🎨 دسته‌بندی‌ها

- **🔘 دکمه‌ها** (`buttons`): متن دکمه‌های inline keyboard
- **💬 پیام‌ها** (`messages`): پیام‌های اصلی و اطلاع‌رسانی
- **✅ تاییدها** (`confirmations`): پیام‌های تایید موفقیت
- **❌ خطاها** (`errors`): پیام‌های خطا
- **📋 راهنماها** (`instructions`): دستورالعمل‌ها و راهنماها
- **🔔 اعلان‌ها** (`notifications`): اعلان‌های سیستمی

## 💡 نکات مهم

1. **کلید (Key)** باید یکتا و ثابت باشد (نباید تغییر کند)
2. از **Emoji** برای زیباتر شدن پیام‌ها استفاده کنید
3. متن **پیش‌فرض** را همیشه در کد بنویسید (برای زمانی که پیام در دیتابیس نباشد)
4. بعد از ویرایش، **کش را پاک کنید**
5. از **متغیرها** به درستی استفاده کنید

## 🔄 مثال کامل

```php
// در WebhookController.php
use App\Support\BotMessageHelper as Msg;

protected function showInvoice($user, Order $order, $messageId = null)
{
    // متغیرهای خودکار
    $vars = Msg::orderVariables($order);
    
    // دریافت متن پیام
    $message = Msg::message('msg_invoice_header', 
        "🛒 *تایید خرید*\n\n▫️ پلن: *{plan_name}*\n▫️ قیمت: *{amount} تومان*",
        $vars
    );

    // ساخت دکمه‌ها
    $keyboard = Keyboard::make()->inline();
    
    $keyboard->row([
        Keyboard::inlineButton([
            'text' => Msg::button('btn_payment_card', '💳 کارت به کارت'),
            'callback_data' => "pay_card_{$order->id}"
        ])
    ]);
    
    $keyboard->row([
        Keyboard::inlineButton([
            'text' => Msg::button('btn_payment_online', '🌐 پرداخت آنلاین'),
            'callback_data' => "pay_online_{$order->id}"
        ])
    ]);

    // ارسال پیام
    $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
}
```

## 🆘 رفع مشکلات

### پیام‌ها تغییر نمی‌کنند
✅ دکمه "پاک کردن کش" را بزنید یا:
```bash
php artisan cache:clear
```

### متغیرها جایگزین نمی‌شوند
✅ مطمئن شوید متغیرها را به درستی با `{}` احاطه کرده‌اید
✅ مطمئن شوید آرایه متغیرها را به تابع ارسال می‌کنید

### پیام پیش‌فرض نمایش می‌دهد
✅ مطمئن شوید پیام در دیتابیس وجود دارد
✅ مطمئن شوید پیام **فعال** (`is_active = true`) است

## 🎓 آموزش ویدیویی

قرار است آموزش ویدیویی کامل در آینده اضافه شود.

---

**توسعه‌دهنده**: VPNMarket Team  
**تاریخ**: فروردین 1404
