<?php

use App\Support\Migrations\BotMessageMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BotMessageMigration::upsert([
            'key' => 'btn_xmplus_panel_account',
            'category' => 'buttons',
            'title' => 'دکمه: حساب پنل XMPlus (منوی ربات)',
            'content' => '🔐 حساب پنل XMPlus',
            'description' => 'متن دکمهٔ «حساب پنل XMPlus» در منوی اینلاین و کیبورد ریپلای (فقط وقتی panel_type=xmplus است). حداکثر حدود ۶۴ کاراکتر برای دکمهٔ اینلاین تلگرام.',
            'is_active' => true,
        ]);

        BotMessageMigration::upsert([
            'key' => 'msg_xmplus_panel_account_empty',
            'category' => 'messages',
            'title' => 'پیام: حساب XMPlus — هنوز نام کاربری ثبت نشده',
            'content' => "<b>🔐 حساب پنل XMPlus (SymmetricNet)</b>\n\nهنوز نام کاربری پنل برای شما ثبت نشده است. معمولاً بعد از <b>اولین خرید</b> یا هنگام انتخاب روش پرداخت، اینجا نمایش داده می‌شود.\n\n🌐 <b>آدرس ورود (با اینترنت ایران معمولاً باز نمی‌شود):</b>\n<a href=\"{panel_url}\">{panel_url}</a>",
            'description' => 'پیام تلگرام/وب وقتی ایمیل XMPlus هنوز خالی است. parse_mode=HTML در تلگرام. متغیر: {panel_url} (از تنظیمات xmplus_panel_url، امن‌سازی در کد انجام می‌شود).',
            'is_active' => true,
        ]);

        BotMessageMigration::upsert([
            'key' => 'msg_xmplus_panel_account',
            'category' => 'messages',
            'title' => 'پیام: حساب XMPlus — نام کاربری و رمز',
            'content' => "<b>🔐 حساب پنل XMPlus (SymmetricNet)</b>\n\n⚠️ <b>توجه:</b> سایت <b>symmetricnet.com</b> معمولاً با <b>اینترنت ایران</b> باز نمی‌شود؛ لطفاً با <b>VPN</b> یا از خارج ایران وارد شوید.\n\n🌐 <b>آدرس ورود به پنل:</b>\n<a href=\"{panel_url}\">{panel_url}</a>\n\n👤 <b>نام کاربری (ایمیل):</b> <code>{email}</code>\n🔑 <b>رمز عبور:</b> <code>{password}</code>\n\n🔒 این اطلاعات را برای دیگران فوروارد نکنید.",
            'description' => 'پیام تلگرام (HTML) و داشبورد وب. متغیرها: {panel_url}, {email}, {password} — مقادیر در کد escape می‌شوند؛ می‌توانید تگ‌های HTML مجاز تلگرام را در متن ثابت استفاده کنید.',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        BotMessageMigration::deleteWhereKeys([
            'btn_xmplus_panel_account',
            'msg_xmplus_panel_account_empty',
            'msg_xmplus_panel_account',
        ]);
    }
};
