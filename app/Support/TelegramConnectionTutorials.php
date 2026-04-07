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
<b>📱 راهنمای اندروید — v2rayNG</b>

<b>📥 دانلود برنامه</b>
👉 <a href="https://github.com/2dust/v2rayNG/releases">دانلود آخرین نسخهٔ v2rayNG از GitHub</a>
<i>فایل APK مخصوص معماری گوشی خود را نصب کنید.</i>

<b>۱) وارد کردن با QR کد</b>
در ربات از <b>سرویس‌های من</b> سرویس را باز کنید و <b>دریافت QR Code</b> را بزنید.
در v2rayNG روی <code>+</code> بزنید → <b>Import config from QRcode</b> (اسکن) یا از گالری تصویر QR را انتخاب کنید.

<b>۲) وارد کردن با لینک (URI)</b>
لینک <code>vless://</code> یا <code>vmess://</code> را از ربات <b>کپی</b> کنید.
در v2rayNG <code>+</code> → <b>Import config from clipboard</b>.

<b>۳) لینک اشتراک (Subscription)</b>
اگر لینک subscription دارید: در v2rayNG منوی همبرگری (☰) → <b>Subscription</b> → اشتراک جدید → آدرس را بچسبانید و <b>به‌روزرسانی</b> را بزنید.

<b>۴) اتصال</b>
کانفیگ را از لیست انتخاب کنید و دکمهٔ <b>V</b> (اتصال) را بزنید.
HTML;
    }

    public static function iosHtml(): string
    {
        return <<<'HTML'
<b>🍏 راهنمای آیفون — V2Box و Streisand</b>

<b>📥 دانلود برنامه‌ها</b>
👉 <a href="https://apps.apple.com/app/v2box-v2ray-client/id6446814690">📲 V2Box — App Store</a>
👉 <a href="https://apps.apple.com/app/streisand/id6450534064">📲 Streisand — App Store</a>
<i>یکی از این دو را نصب کنید (هر دو برای وارد کردن کانفیگ مناسب‌اند).</i>

<b>V2Box — QR / URI / اشتراک</b>
• <b>QR:</b> بخش <b>Configs</b> → <code>+</code> → اسکن QR یا وارد کردن از تصویر.
• <b>URI:</b> لینک را از ربات کپی کنید → <b>Configs</b> → <code>+</code> → <b>Import from Clipboard</b>.
• <b>Subscription:</b> اگر لینک ساب دارید، در بخش مربوط به اشتراک/Subscription آدرس را اضافه و به‌روز کنید.

<b>Streisand — خلاصه</b>
لینک یا QR را از ربات بگیرید؛ در اپ گزینهٔ <b>وارد کردن از کلیپ‌بورد</b> یا <b>اسکن QR</b> را انتخاب کنید (نام دقیق منو ممکن است کمی فرق کند).

<b>اتصال</b>
پس از اضافه شدن کانفیگ، اتصال VPN را روشن کنید؛ در صورت درخواست iOS گزینهٔ <b>Allow VPN</b> را تأیید کنید.
HTML;
    }

    public static function windowsHtml(): string
    {
        return <<<'HTML'
<b>💻 راهنمای ویندوز — v2rayN</b>

<b>📥 دانلود برنامه</b>
👉 <a href="https://github.com/2dust/v2rayN/releases">دانلود v2rayN از GitHub</a>
<i>پیشنهاد: فایل</i> <code>v2rayN-With-Core.zip</code> <i>— بعد از باز کردن،</i> <code>v2rayN.exe</code> <i>را اجرا کنید.</i>

<b>۱) وارد کردن با لینک (URI)</b>
لینک کانفیگ را از ربات کپی کنید. پنجرهٔ v2rayN را باز کنید و کلیدهای <code>Ctrl+V</code> را بزنید تا سرور از کلیپ‌بورد اضافه شود.
(جایگزین: منوی <b>Servers</b> → <b>Import bulk URL from clipboard</b>.)

<b>۲) QR کد</b>
اگر فقط QR دارید، با گوشی اسکن کنید و لینک را برای خود بفرستید و سپس در v2rayN همان <code>Ctrl+V</code> را بزنید.

<b>۳) لینک اشتراک (Subscription)</b>
منوی <b>Subscription</b> → <b>Subscription group setting</b> → <b>Add</b> → آدرس subscription را بچسبانید → ذخیره و سپس <b>Update subscription</b> / به‌روزرسانی گروه.

<b>۴) پروکسی و اتصال</b>
روی آیکن v2rayN در نوار وظیفه راست‌کلیک → <b>System Proxy</b> → <b>Set system proxy</b>.
سپس از منوی <b>Servers</b> همان سرور را انتخاب و متصل شوید.
HTML;
    }
}
