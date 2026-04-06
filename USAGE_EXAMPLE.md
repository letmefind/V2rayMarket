# نمونه استفاده از سیستم مدیریت پیام‌ها

## قبل از تغییرات

```php
// در WebhookController.php - قبل
$keyboard->row([
    Keyboard::inlineButton([
        'text' => '💳 کارت به کارت',
        'callback_data' => "pay_card_{$order->id}"
    ])
]);

$keyboard->row([
    Keyboard::inlineButton([
        'text' => '🌐 پرداخت آنلاین ارزی / کریپتو',
        'callback_data' => "pay_xmplusgw_{$order->id}"
    ])
]);
```

## بعد از تغییرات

```php
// در WebhookController.php - بعد
use App\Support\BotMessageHelper as Msg;

$keyboard->row([
    Keyboard::inlineButton([
        'text' => Msg::button('btn_payment_card', '💳 کارت به کارت'),
        'callback_data' => "pay_card_{$order->id}"
    ])
]);

$keyboard->row([
    Keyboard::inlineButton([
        'text' => Msg::button('btn_payment_online', '🌐 پرداخت آنلاین ارزی / کریپتو'),
        'callback_data' => "pay_xmplusgw_{$order->id}"
    ])
]);
```

## مزایا

✅ حالا می‌توانید از پنل ادمین متن دکمه را تغییر دهید بدون تغییر کد  
✅ اگر پیامی در دیتابیس نباشد، متن پیش‌فرض استفاده می‌شود  
✅ کش خودکار برای سرعت بالا  

## مثال با متغیرها

```php
// قبل
$message = "✅ سرویس شما ({$plan->name}) فعال شد.\n{$config_link}";

// بعد
$message = Msg::confirmation('conf_service_activated', '✅ سرویس فعال شد', [
    'plan_name' => $plan->name,
    'config_link' => $config_link
]);
```

حالا می‌توانید از پنل متن را به این شکل تغییر دهید:

```
✅ سرویس {plan_name} شما با موفقیت فعال شد!

🔗 لینک اتصال:
{config_link}

📱 از اپلیکیشن‌های V2Ray استفاده کنید.
```

و کد تغییری نمی‌کند!
