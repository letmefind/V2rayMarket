<?php

use App\Models\BotMessage;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BotMessage::updateOrCreate(
            ['key' => 'btn_main_renew_service'],
            [
                'category' => 'buttons',
                'title' => 'دکمه: تمدید سرویس (منوی اصلی ربات)',
                'content' => '🔄 تمدید سرویس',
                'description' => 'جایگزین دکمهٔ کیف پول در منوی اصلی (اینلاین و کیبورد ریپلای). حداکثر حدود ۶۴ کاراکتر برای دکمهٔ اینلاین.',
                'is_active' => true,
            ]
        );

        BotMessage::updateOrCreate(
            ['key' => 'msg_renew_service_picker'],
            [
                'category' => 'messages',
                'title' => 'پیام: انتخاب سرویس برای تمدید',
                'content' => "🔄 *تمدید سرویس*\n\nیکی از سرویس‌های زیر را برای تمدید انتخاب کنید:",
                'description' => 'متن بالای لیست سرویس‌ها هنگام ورود از منوی «تمدید سرویس». parse_mode=MarkdownV2 در ربات.',
                'is_active' => true,
            ]
        );

        BotMessage::clearCache();
    }

    public function down(): void
    {
        BotMessage::whereIn('key', [
            'btn_main_renew_service',
            'msg_renew_service_picker',
        ])->delete();
        BotMessage::clearCache();
    }
};
