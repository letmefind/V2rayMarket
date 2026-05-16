<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        $legacyWelcome = null;
        $legacyStart = null;
        if (Schema::hasTable('telegram_bot_settings')) {
            $legacyWelcome = DB::table('telegram_bot_settings')->where('key', 'welcome_message')->value('value');
            $legacyStart = DB::table('telegram_bot_settings')->where('key', 'start_message')->value('value');
        }

        $instanceIds = collect();
        if (Schema::hasColumn('bot_messages', 'instance_id')) {
            $instanceIds = $instanceIds->merge(
                DB::table('bot_messages')->distinct()->pluck('instance_id')
            );
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'instance_id')) {
            $instanceIds = $instanceIds->merge(
                DB::table('users')->distinct()->pluck('instance_id')
            );
        }
        $instanceIds = $instanceIds->filter(fn ($id) => is_string($id) && $id !== '')->unique()->values();
        if ($instanceIds->isEmpty()) {
            $instanceIds = collect(['default']);
        }

        $now = now();
        $defs = [
            'msg_welcome' => [
                'legacy' => $legacyWelcome,
                'title' => 'پیام: خوش‌آمدگویی کاربر جدید',
                'description' => 'اولین ورود به ربات. متغیر: {userFirstName}',
                'default' => "🌟 خوش آمدید {userFirstName} عزیز!\n\nبرای شروع، یکی از گزینه‌های منو را انتخاب کنید:",
            ],
            'msg_start' => [
                'legacy' => $legacyStart,
                'title' => 'پیام: دستور /start (کاربر موجود)',
                'description' => 'وقتی کاربر قبلاً ثبت‌نام کرده و دوباره /start می‌زند.',
                'default' => 'سلام مجدد! لطفاً یک گزینه را انتخاب کنید:',
            ],
        ];

        foreach ($instanceIds as $instanceId) {
            foreach ($defs as $key => $meta) {
                $exists = DB::table('bot_messages')
                    ->where('instance_id', $instanceId)
                    ->where('key', $key)
                    ->exists();
                if ($exists) {
                    continue;
                }

                $content = is_string($meta['legacy']) && trim($meta['legacy']) !== ''
                    ? $meta['legacy']
                    : $meta['default'];

                $row = [
                    'key' => $key,
                    'category' => 'messages',
                    'title' => $meta['title'],
                    'content' => $content,
                    'description' => $meta['description'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (Schema::hasColumn('bot_messages', 'instance_id')) {
                    $row['instance_id'] = $instanceId;
                }

                DB::table('bot_messages')->insert($row);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        DB::table('bot_messages')->whereIn('key', ['msg_welcome', 'msg_start'])->delete();
    }
};
