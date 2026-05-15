<?php

use App\Support\Migrations\BotMessageMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BotMessageMigration::upsert([
            'key' => 'msg_iran_share_guide',
            'category' => 'messages',
            'title' => 'پیام: راهنمای ارسال کانفیگ به ایران (کد ۵ رقمی)',
            'content' => "📝 راهنما (برای کسی که داخل ایران است):\n\n"
                ."این آدرس را در تماس بخوانید تا در مرورگر تایپ کند:\n{share_url}\n\n"
                ."کد ۵ رقمی را بگویید:\n{share_code}\n\n"
                .'بعد از وارد کردن کد، لینک اشتراک یا QR را می‌گیرد؛ در برنامه VPN وارد کند یا QR را اسکن کند.',
            'description' => 'پس از زدن «ارسال به ایران» در ربات. متغیرها: {share_url} (مثلاً bale.cyou), {share_code} (کد ۵ رقمی)',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        BotMessageMigration::deleteWhereKey('msg_iran_share_guide');
    }
};
