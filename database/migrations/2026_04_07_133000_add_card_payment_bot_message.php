<?php

use App\Models\BotMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        BotMessage::updateOrCreate(
            ['key' => 'msg_card_payment_info'],
            [
                'category' => 'messages',
                'title' => 'پیام: اطلاعات پرداخت کارت به کارت',
                'content' => "💳 *پرداخت کارت به کارت*\n\nلطفاً مبلغ *{amount} تومان* را به حساب زیر واریز نمایید:\n\n👤 *به نام:* {card_holder}\n💳 *شماره کارت:*\n`{card_number}`\n\n🔔 *مهم:* پس از واریز، *فقط عکس رسید* را در همین چت ارسال کنید\\.",
                'description' => 'نمایش اطلاعات کارت برای پرداخت دستی. متغیرها: {amount}, {card_holder}, {card_number}',
                'is_active' => true,
            ]
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        BotMessage::where('key', 'msg_card_payment_info')->delete();
    }
};
