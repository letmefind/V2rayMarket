<?php

namespace App\Support;

/**
 * متن پیش‌فرض آموزش اتصال برای تلگرام (parse_mode: HTML).
 * در پنل Filament همین قالب را می‌توانید ویرایش کنید.
 */
class TelegramConnectionTutorials
{
    public static function androidHtml(): string
    {
        return <<<'HTML'
<b>📱 راهنمای اندروید — v2rayNG و Happ</b>

<b>🔹 v2rayNG</b>
<b>📥 دانلود</b>
• <a href="https://github.com/2dust/v2rayNG/releases">اصلی — GitHub (انتخاب APK مناسب گوشی)</a>
• <a href="https://help.bale.cyou/downloads/V2rayNG.apk">ایران — آینهٔ دانلود</a>

<b>در v2rayNG: QR / URI / اشتراک</b>
• <b>QR:</b> ربات → <b>سرویس‌های من</b> → <b>دریافت QR</b> — در اپ <code>+</code> → <b>Import config from QRcode</b> (اسکن یا گالری).
• <b>URI:</b> لینک <code>vless://</code> / <code>vmess://</code> را کپی کنید — <code>+</code> → <b>Import config from clipboard</b>.
• <b>Subscription:</b> منوی ☰ → <b>Subscription</b> → جدید → URL ساب را بچسبانید → به‌روزرسانی.
• <b>اتصال:</b> کانفیگ را انتخاب کنید و دکمهٔ <b>V</b> را بزنید.

<b>🔹 Happ</b>
<b>📥 دانلود</b>
• <a href="https://play.google.com/store/apps/details?id=com.happproxy">اصلی — Google Play</a>
• <a href="https://help.bale.cyou/downloads/Happ.apk">ایران — آینهٔ APK</a>

<b>در Happ: QR / URI / اشتراک</b>
از منوی افزودن (<code>+</code>) یا بخش پروفایل‌ها معمولاً <b>اسکن QR</b>، <b>Import از کلیپ‌بورد</b> (لینک اشتراک یا URI) و <b>افزودن Subscription با لینک</b> در دسترس است؛ بسته به نسخهٔ اپ یکی را انتخاب کنید.
پروفایل را انتخاب و <b>اتصال</b> را روشن کنید.
HTML;
    }

    public static function iosHtml(): string
    {
        return <<<'HTML'
<b>🍏 راهنمای آیفون — V2Box، Streisand و Happ</b>

<b>🔹 V2Box</b>
<b>📥 دانلود</b>
• <a href="https://apps.apple.com/app/v2box-v2ray-client/id6446814690">اصلی — App Store</a>
<i>نسخهٔ آینهٔ مستقیم برای iOS معمولاً عرضه نمی‌شود؛ در صورت فیلتر، از اپل‌آیدی مناسب یا شبکهٔ کمکی برای App Store استفاده کنید.</i>

<b>V2Box — QR / URI / اشتراک</b>
• <b>QR:</b> <b>Configs</b> → <code>+</code> → اسکن یا تصویر QR از ربات.
• <b>URI:</b> کپی از ربات → <b>Configs</b> → <code>+</code> → <b>Import from Clipboard</b>.
• <b>Subscription:</b> لینک ساب را در بخش اشتراک اپ اضافه و به‌روز کنید.

<b>🔹 Streisand</b>
<b>📥 دانلود</b>
• <a href="https://apps.apple.com/app/streisand/id6450534064">اصلی — App Store</a>

<b>Streisand — خلاصه</b>
لینک یا QR را از ربات بگیرید؛ <b>وارد کردن از کلیپ‌بورد</b> یا <b>اسکن QR</b> را در اپ انتخاب کنید (نام منو ممکن است کمی فرق کند).

<b>🔹 Happ</b>
<b>📥 دانلود</b>
• <a href="https://apps.apple.com/app/happ-proxy-utility/id6504287215">اصلی — App Store (Happ - Proxy Utility)</a>
<i>برای iOS فقط نصب از App Store؛ فایل آینهٔ جدا مانند APK در لیست ایران ارائه نشده است.</i>

<b>Happ — QR / URI / اشتراک</b>
پس از نصب، از گزینهٔ افزودن پروفایل معمولاً می‌توانید <b>QR</b>، <b>چسباندن لینک (URI یا subscription)</b> یا <b>وارد کردن از کلیپ‌بورد</b> را بزنید؛ پروفایل را انتخاب و اتصال VPN را فعال کنید. در صورت درخواست iOS، <b>Allow VPN</b> را تأیید کنید.

<b>اتصال (همهٔ اپ‌ها)</b>
پس از اضافه شدن کانفیگ، VPN را روشن کنید و در صورت نیاز مجوز VPN را بدهید.
HTML;
    }

    public static function windowsHtml(): string
    {
        return <<<'HTML'
<b>💻 راهنمای ویندوز — v2rayN و Happ</b>

<b>🔹 v2rayN</b>
<b>📥 دانلود</b>
• <a href="https://github.com/2dust/v2rayN/releases">اصلی — GitHub (پیشنهاد: v2rayN-With-Core.zip)</a>
• <a href="https://help.bale.cyou/downloads/V2rayN.zip">ایران — آینهٔ ZIP</a>
بعد از باز کردن آرشیو، <code>v2rayN.exe</code> را اجرا کنید.

<b>v2rayN — URI / QR / اشتراک</b>
• <b>URI:</b> لینک را کپی کنید → در پنجرهٔ v2rayN <code>Ctrl+V</code> (یا <b>Servers</b> → <b>Import bulk URL from clipboard</b>).
• <b>QR:</b> با گوشی اسکن کنید، لینک را برای ویندوز بفرستید و دوباره <code>Ctrl+V</code> بزنید.
• <b>Subscription:</b> <b>Subscription</b> → <b>Subscription group setting</b> → <b>Add</b> → URL → به‌روزرسانی گروه.
• <b>پروکسی:</b> راست‌کلیک روی آیکن v2rayN در تسک‌بار → <b>System proxy</b> → <b>Set system proxy</b> → از <b>Servers</b> سرور را انتخاب کنید.

<b>🔹 Happ</b>
<b>📥 دانلود</b>
• <a href="https://www.happ.su/main">اصلی — سایت رسمی Happ (بخش دانلود ویندوز)</a>
• <a href="https://help.bale.cyou/downloads/Happ.exe">ایران — آینهٔ Happ.exe</a>

<b>Happ — URI / QR / اشتراک</b>
پس از اجرا، معمولاً با <b>افزودن پروفایل</b> می‌توانید لینک اشتراک یا URI را بچسبانید، از <b>QR</b> استفاده کنید یا کانفیگ را از کلیپ‌بورد وارد کنید؛ سپس پروفایل را انتخاب و اتصال را فعال کنید.
HTML;
    }
}
