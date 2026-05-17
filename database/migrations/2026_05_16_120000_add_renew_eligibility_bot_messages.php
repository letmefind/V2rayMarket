<?php

use App\Support\Migrations\BotMessageMigration;
use App\Support\XmplusRenewalEligibility;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        BotMessageMigration::upsert([
            'key' => 'msg_renew_not_eligible',
            'category' => 'messages',
            'title' => 'پیام: تمدید مجاز نیست (حجم کافی)',
            'content' => XmplusRenewalEligibility::USER_DENIAL_MESSAGE,
            'description' => 'وقتی سرویس هنوز بیش از ۱۰٪ حجم دارد؛ XMPlus',
            'is_active' => true,
        ]);

        BotMessageMigration::upsert([
            'key' => 'msg_renew_none_eligible',
            'category' => 'messages',
            'title' => 'پیام: هیچ سرویسی برای تمدید نیست',
            'content' => XmplusRenewalEligibility::USER_EMPTY_RENEW_MENU_MESSAGE,
            'description' => 'منوی تمدید وقتی همهٔ سرویس‌ها هنوز حجم دارند',
            'is_active' => true,
        ]);
    }

    public function down(): void
    {
        BotMessageMigration::deleteWhereKeys([
            'msg_renew_not_eligible',
            'msg_renew_none_eligible',
        ]);
    }
};
