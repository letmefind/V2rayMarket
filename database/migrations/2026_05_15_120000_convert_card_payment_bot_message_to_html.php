<?php

use App\Support\Migrations\BotMessageMigration;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $html = "💳 <b>پرداخت کارت به کارت</b>\n\n"
            .'اگر کاربر قدیمی ما هستید از طریق تماس به @BypaxY پیام دهید تا اطلاعات کارت برای شما ارسال شود؛ عکس فیش واریزی را در همینجا ارسال فرمایید.'."\n\n"
            ."لطفاً مبلغ <b>{amount} تومان</b> را به حساب زیر واریز نمایید:\n\n"
            ."👤 <b>به نام:</b> {card_holder}\n"
            ."💳 <b>شماره کارت</b> <i>(لمس کنید تا کپی شود)</i>:\n"
            ."<code>{card_number}</code>\n\n"
            .'🔔 <b>مهم:</b> پس از واریز، <b>فقط عکس رسید</b> را در همین چت ارسال کنید.';

        BotMessageMigration::upsert([
            'key' => 'msg_card_payment_info',
            'category' => 'messages',
            'title' => 'پیام: اطلاعات پرداخت کارت به کارت',
            'content' => $html,
            'description' => 'فرمت HTML (نه Markdown). تگ‌های مجاز: <b>, <i>, <code>. متغیرها: {amount}, {card_holder}, {card_number} — شماره کارت داخل <code> با لمس کپی می‌شود.',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        BotMessageMigration::updateByKey('msg_card_payment_info', [
            'content' => "💳 *پرداخت کارت به کارت*\n\nلطفاً مبلغ *{amount} تومان* را به حساب زیر واریز نمایید:\n\n👤 *به نام:* {card_holder}\n💳 *شماره کارت:*\n`{card_number}`\n\n🔔 *مهم:* پس از واریز، *فقط عکس رسید* را در همین چت ارسال کنید.",
        ]);
    }
};
