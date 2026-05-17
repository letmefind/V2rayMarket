<?php

namespace App\Console\Commands;

use App\Models\BotMessage;
use App\Support\Migrations\BotMessageMigration;
use App\Support\XmplusRenewalEligibility;
use Illuminate\Console\Command;

class SyncRenewEligibilityBotMessagesCommand extends Command
{
    protected $signature = 'vpnmarket:sync-renew-eligibility-messages';

    protected $description = 'Upsert bot messages for XMPlus renewal eligibility (msg_renew_not_eligible, msg_renew_none_eligible)';

    public function handle(): int
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

        BotMessage::clearCache();

        $this->info('Renew eligibility bot messages synced for instance: '.config('app.instance_id'));
        $this->line('  - msg_renew_not_eligible');
        $this->line('  - msg_renew_none_eligible');

        return self::SUCCESS;
    }
}
