# تمدید سرویس و خرید ترافیک در XMPlus

این سند توضیح می‌دهد که چگونه تمدید سرویس و خرید ترافیک در VPNMarket با XMPlus کار می‌کند.

## 📋 فهرست

1. [تمدید سرویس (Service Renewal)](#تمدید-سرویس-service-renewal)
2. [خرید ترافیک (Traffic Packages)](#خرید-ترافیک-traffic-packages)
3. [تست‌های پیشنهادی](#تست‌های-پیشنهادی)
4. [عیب‌یابی](#عیب‌یابی)

---

## تمدید سرویس (Service Renewal)

### API Endpoint
- **URL**: `/api/client/service/renew`
- **Method**: `POST`
- **مستندات**: https://docs.xmplus.dev/api/client.html#_10-service-renewal

### پارامترها
```json
{
    "email": "user@example.com",
    "passwd": "password",
    "sid": 123
}
```

| پارامتر | نوع | توضیحات |
|---------|-----|---------|
| `email` | string | ایمیل کاربر در XMPlus |
| `passwd` | string | رمز عبور کاربر |
| `sid` | int | شناسه سرویس (Service ID) |

### پاسخ موفق
```json
{
    "status": "success",
    "code": 100,
    "invid": "5EGFZSVJA1JMA1S6NE0T",
    "message": "Invoice Created"
}
```

### نحوه کار در VPNMarket

#### 1️⃣ شروع تمدید
وقتی کاربر روی دکمه "تمدید سرویس" کلیک می‌کند:
```php
// WebhookController.php
if (Str::startsWith($data, 'renew_order_')) {
    $orderId = (int) Str::after($data, 'renew_order_');
    // ... نمایش روش‌های پرداخت
}
```

#### 2️⃣ ایجاد فاکتور تمدید
```php
// XmplusProvisioningService::doRenewal()
public static function doRenewal(
    Settings $settings,
    Order $renewalOrder,
    Order $originalOrder,
    bool $shopPaymentAlreadyCollected = false
): array {
    // بررسی می‌کند که آیا service اصلی هنوز وجود دارد
    $serviceExists = false;
    if ($invid !== '') {
        try {
            $svcCheck = $api->serviceInfo($email, $passwdPlain, $sid);
            if (self::apiOk($svcCheck)) {
                $serviceExists = true;
            }
        } catch (\Throwable $e) {
            // اگر service حذف شده باشد، فاکتور قبلی را پاک می‌کنیم
            if (str_contains(strtolower($e->getMessage()), 'service not found')) {
                $invid = '';
                $renewalOrder->forceFill(['xmplus_inv_id' => null])->save();
            }
        }
    }
    
    // اگر فاکتور وجود نداشته باشد، یک فاکتور جدید می‌سازیم
    if ($invid === '') {
        $renew = $api->serviceRenew($email, $passwdPlain, $sid);
        $invid = $renew['invid'] ?? null;
        $renewalOrder->forceFill(['xmplus_inv_id' => $invid])->save();
    }
    
    // ... ادامه منطق پرداخت
}
```

#### 3️⃣ فلوچارت تمدید
```
کاربر کلیک روی "تمدید سرویس"
    ↓
بررسی: آیا service اصلی وجود دارد؟
    ├─ بله → استفاده از فاکتور موجود (اگر هست)
    │         یا ایجاد فاکتور جدید با serviceRenew()
    └─ خیر → پاک کردن فاکتور قبلی
              ↓
        ایجاد فاکتور جدید با serviceRenew()
              ↓
        ذخیره xmplus_inv_id در Order
              ↓
        پرداخت (اگر تنظیم شده باشد)
              ↓
        بررسی وضعیت و فعال‌سازی
```

### ویژگی‌های امنیتی
- ✅ بررسی خودکار وجود service قبل از تمدید
- ✅ حذف فاکتور نامعتبر اگر service پاک شده باشد
- ✅ جلوگیری از تمدید سرویس‌های حذف‌شده
- ✅ لاگ کامل تمام مراحل

---

## خرید ترافیک (Traffic Packages)

### API Endpoint
- **URL**: `/api/client/service/addtraffic`
- **Method**: `POST`
- **مستندات**: https://docs.xmplus.dev/api/client.html#_11-service-add-traffic

### پارامترها
```json
{
    "email": "user@example.com",
    "passwd": "password",
    "sid": 123,
    "pid": 5
}
```

| پارامتر | نوع | توضیحات |
|---------|-----|---------|
| `email` | string | ایمیل کاربر در XMPlus |
| `passwd` | string | رمز عبور کاربر |
| `sid` | int | شناسه سرویس (Service ID) |
| `pid` | int | شناسه پکیج ترافیک در XMPlus |

### پاسخ موفق
```json
{
    "status": "success",
    "code": 100,
    "invid": "5EGFZSVJA1JMA1S6NE0T",
    "message": "Invoice Created"
}
```

### نحوه راه‌اندازی در VPNMarket

#### 1️⃣ دریافت لیست پکیج‌های ترافیک
```php
// XmplusCatalog.php
public static function get(Settings $settings): array
{
    $result = [
        'full' => [],      // پکیج‌های کامل
        'traffic' => [],   // پکیج‌های ترافیک ✅
        'error' => null,
    ];
    
    // دریافت پکیج‌های ترافیک
    $traffPkgResp = $api->postClient('/api/client/packages/traffic', [], 'packages_traffic');
    if (isset($traffPkgResp['packages']) && is_array($traffPkgResp['packages'])) {
        $result['traffic'] = $traffPkgResp['packages'];
    }
    
    return $result;
}
```

#### 2️⃣ نمایش پکیج‌های ترافیک در ربات
```php
// WebhookController.php
protected function sendPlansXmplus(...)
{
    // ... نمایش پکیج‌های full
    
    // نمایش پکیج‌های ترافیک
    foreach ($catalog['traffic'] ?? [] as $p) {
        $pid = (int) ($p['id'] ?? 0);
        $name = (string) ($p['name'] ?? 'pid '.$pid);
        
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => $name.' · ترافیک',
                'callback_data' => 'xmplus_unmapped_'.$pid,
            ]),
        ]);
    }
}
```

#### 3️⃣ ساخت Plan برای پکیج ترافیک
در پنل ادمین Filament:
1. رفتن به **Plans** > **Create**
2. تنظیم نام: `ترافیک 50GB` (مثال)
3. تنظیم `xmplus_package_id` = `2` (pid پکیج ترافیک در XMPlus)
4. تنظیم قیمت
5. فعال کردن Plan

#### 4️⃣ خرید ترافیک توسط کاربر

**⚠️ توجه**: در حال حاضر، API `serviceAddTraffic` در `XmplusService.php` اضافه شده است، اما:
- ✅ API موجود است
- ❌ UI برای انتخاب سرویس (sid) موجود نیست
- ❌ منطق خرید ترافیک در `XmplusProvisioningService` پیاده‌سازی نشده

### منطق پیشنهادی برای خرید ترافیک

```php
// XmplusProvisioningService::purchaseTrafficPackage()
public static function purchaseTrafficPackage(
    Settings $settings,
    Order $order,
    int $sid,  // سرویس فعلی کاربر
    int $pid   // پکیج ترافیک
): array {
    $api = new XmplusService($settings);
    
    // دریافت اطلاعات کاربر
    $email = $order->user->panel_username ?? $order->user->email;
    $passwd = $order->user->plain_password ?? '';
    
    // ایجاد فاکتور برای خرید ترافیک
    $result = $api->serviceAddTraffic($email, $passwd, $sid, $pid);
    
    if (!self::apiOk($result)) {
        throw new RuntimeException('خرید ترافیک ناموفق: '.json_encode($result));
    }
    
    $invid = $result['invid'] ?? '';
    $order->forceFill(['xmplus_inv_id' => $invid])->save();
    
    // ... منطق پرداخت
    
    return [
        'ok' => true,
        'invid' => $invid,
    ];
}
```

### UI پیشنهادی برای خرید ترافیک

1. **دکمه "افزودن ترافیک" در لیست سرویس‌های کاربر**
```php
// WebhookController.php
$keyboard->row([
    Keyboard::inlineButton([
        'text' => '➕ افزودن ترافیک',
        'callback_data' => 'add_traffic_'.$order->id,
    ]),
]);
```

2. **نمایش لیست پکیج‌های ترافیک**
```php
if (Str::startsWith($data, 'add_traffic_')) {
    $orderId = (int) Str::after($data, 'add_traffic_');
    $order = Order::with('user')->find($orderId);
    
    // دریافت لیست پکیج‌های ترافیک
    $catalog = XmplusCatalog::get($this->settings);
    
    foreach ($catalog['traffic'] ?? [] as $pkg) {
        $keyboard->row([
            Keyboard::inlineButton([
                'text' => $pkg['name'].' - '.$pkg['traffic'],
                'callback_data' => 'buy_traffic_'.$orderId.'_'.$pkg['id'],
            ]),
        ]);
    }
}
```

3. **پردازش خرید ترافیک**
```php
if (preg_match('/^buy_traffic_(\d+)_(\d+)$/', $data, $m)) {
    $orderId = (int) $m[1];
    $trafficPid = (int) $m[2];
    
    // ایجاد Order جدید برای خرید ترافیک
    // منطق مشابه خرید پلن عادی
}
```

---

## تست‌های پیشنهادی

### تست تمدید سرویس

#### تست 1: تمدید سرویس فعال
```bash
# 1. خرید یک سرویس
# 2. منتظر تا نزدیک انقضا شود
# 3. کلیک روی "تمدید سرویس"
# 4. پرداخت
# نتیجه مورد انتظار: سرویس تمدید شود (expire_date افزایش یابد)
```

#### تست 2: تمدید سرویس حذف‌شده
```bash
# 1. خرید یک سرویس (فرض: sid=100)
# 2. حذف سرویس از پنل XMPlus (دستی)
# 3. تلاش برای تمدید
# نتیجه مورد انتظار: فاکتور قبلی پاک شود، فاکتور جدید ساخته شود
# لاگ: "XMPlus تمدید: service اصلی یافت نشد؛ فاکتور قبلی نامعتبر شد"
```

#### تست 3: تمدید چندباره
```bash
# 1. تمدید سرویس
# 2. بدون پرداخت، دوباره کلیک تمدید
# نتیجه مورد انتظار: از همان فاکتور استفاده شود
# لاگ: "XMPlus تمدید بدون serviceRenew جدید (همان فاکتور)"
```

### تست خرید ترافیک

#### تست 1: بررسی API
```bash
# در Tinker:
php artisan tinker

$api = new \App\Services\XmplusService(app(\App\Models\Settings::class));
$result = $api->serviceAddTraffic(
    'test@example.com',
    'password',
    123,  // sid سرویس فعال
    2     // pid پکیج ترافیک
);

dd($result);
# نتیجه مورد انتظار: ['status' => 'success', 'invid' => '...']
```

#### تست 2: UI (زمانی که پیاده‌سازی شد)
```bash
# 1. خرید یک سرویس
# 2. کلیک روی "افزودن ترافیک"
# 3. انتخاب پکیج ترافیک
# 4. پرداخت
# نتیجه مورد انتظار: ترافیک سرویس افزایش یابد
```

---

## عیب‌یابی

### خطا: "Service not found"
**علت**: سرویس در پنل XMPlus حذف شده است.
**راه‌حل**: 
```php
// منطق تمدید خودکار این را handle می‌کند:
if (str_contains($errMsg, 'service not found')) {
    $invid = '';
    $renewalOrder->forceFill(['xmplus_inv_id' => null])->save();
    // فاکتور جدید ساخته می‌شود
}
```

### خطا: "XMPlus تمدید ناموفق"
**علت**: 
- ایمیل یا رمز عبور اشتباه
- sid نامعتبر
- خطای API XMPlus

**راه‌حل**:
1. بررسی لاگ‌ها: `storage/logs/xmplus-*.log`
2. تست API دستی با Postman/curl
3. بررسی تنظیمات XMPlus در Theme Settings

### خطا: "پکیج ترافیک نمایش داده نمی‌شود"
**علت**: پنل XMPlus پکیج ترافیک فعالی ندارد.
**راه‌حل**:
1. رفتن به پنل XMPlus > Packages
2. ایجاد یک Traffic Package جدید
3. فعال کردن آن
4. تست `/api/client/packages/traffic`

---

## خلاصه وضعیت

| ویژگی | API | UI | وضعیت |
|-------|-----|-----|-------|
| تمدید سرویس | ✅ | ✅ | **کامل** |
| لیست پکیج‌های ترافیک | ✅ | ✅ | **کامل** |
| خرید ترافیک (API) | ✅ | ❌ | **نیاز به UI** |
| خرید ترافیک (Logic) | ❌ | ❌ | **نیاز به پیاده‌سازی** |

### کارهای باقی‌مانده برای خرید ترافیک
1. [ ] اضافه کردن دکمه "افزودن ترافیک" در لیست سرویس‌ها
2. [ ] پیاده‌سازی `handleAddTraffic()` در `WebhookController`
3. [ ] پیاده‌سازی `purchaseTrafficPackage()` در `XmplusProvisioningService`
4. [ ] تست کامل فلوی خرید ترافیک

---

## مراجع
- [XMPlus Client API - Service Renewal](https://docs.xmplus.dev/api/client.html#_10-service-renewal)
- [XMPlus Client API - Service Add Traffic](https://docs.xmplus.dev/api/client.html#_11-service-add-traffic)
- [VPNMarket - XmplusProvisioningService.php](../app/Services/XmplusProvisioningService.php)
- [VPNMarket - XmplusService.php](../app/Services/XmplusService.php)
