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

        $rows = [
            [
                'key' => 'msg_wallet_xmplus_intro',
                'category' => 'messages',
                'title' => 'پیام: توضیح کیف پول XMPlus',
                'content' => "در حالت *XMPlus* موجودی طبق API (`/api/client/account/info` → `money`) است، نه کیف پول VPNMarket.\n\n",
                'description' => 'متن توضیح ابتدای صفحه کیف پول در حالت XMPlus',
                'is_active' => true,
            ],
            [
                'key' => 'msg_wallet_xmplus_not_linked',
                'category' => 'messages',
                'title' => 'پیام: کیف پول XMPlus هنوز لینک نشده',
                'content' => 'پس از اولین خرید، حساب شما به XMPlus وصل می‌شود و موجودی اینجا نمایش داده می‌شود.',
                'description' => 'وقتی کاربر هنوز حساب لینک‌شده در XMPlus ندارد',
                'is_active' => true,
            ],
            [
                'key' => 'msg_wallet_xmplus_error',
                'category' => 'errors',
                'title' => 'خطا: دریافت موجودی XMPlus',
                'content' => '⚠️ خطا در دریافت موجودی از XMPlus.',
                'description' => 'وقتی API پنل خطا می‌دهد',
                'is_active' => true,
            ],
            [
                'key' => 'msg_wallet_xmplus_balance',
                'category' => 'messages',
                'title' => 'پیام: نمایش موجودی XMPlus',
                'content' => 'موجودی (XMPlus): *{balance}*',
                'description' => 'نمایش موجودی با متغیر {balance}',
                'is_active' => true,
            ],
        ];

        foreach ($rows as $row) {
            BotMessage::updateOrCreate(['key' => $row['key']], $row);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        BotMessage::whereIn('key', [
            'msg_wallet_xmplus_intro',
            'msg_wallet_xmplus_not_linked',
            'msg_wallet_xmplus_error',
            'msg_wallet_xmplus_balance',
        ])->delete();
    }
};
