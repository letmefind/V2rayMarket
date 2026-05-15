<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private array $tenantTables = [
        'users',
        'plans',
        'orders',
        'settings',
        'transactions',
        'discount_codes',
        'discount_code_usages',
        'notifications',
        'user_trials',
        'inbounds',
        'bot_messages',
        'tickets',
        'ticket_replies',
    ];

    public function up(): void
    {
        foreach ($this->tenantTables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'instance_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('instance_id', 64)->default('default')->after('id');
                $blueprint->index('instance_id');
            });

            DB::table($table)->whereNull('instance_id')->orWhere('instance_id', '')->update(['instance_id' => 'default']);
        }

        if (Schema::hasTable('service_shares') && ! Schema::hasColumn('service_shares', 'source_instance_id')) {
            Schema::table('service_shares', function (Blueprint $table): void {
                $table->string('source_instance_id', 64)->nullable()->after('order_id');
                $table->index('source_instance_id');
            });
        }

        if (Schema::hasTable('users')) {
            $this->tryDropUnique('users', ['email']);
            $this->tryDropUnique('users', ['telegram_chat_id']);
            Schema::table('users', function (Blueprint $table): void {
                $table->unique(['instance_id', 'email'], 'users_instance_email_unique');
                $table->unique(['instance_id', 'telegram_chat_id'], 'users_instance_telegram_unique');
            });
        }

        if (Schema::hasTable('settings')) {
            $this->tryDropUnique('settings', ['key']);
            Schema::table('settings', function (Blueprint $table): void {
                $table->unique(['instance_id', 'key'], 'settings_instance_key_unique');
            });
        }

        if (Schema::hasTable('bot_messages')) {
            $this->tryDropUnique('bot_messages', ['key']);
            Schema::table('bot_messages', function (Blueprint $table): void {
                $table->unique(['instance_id', 'key'], 'bot_messages_instance_key_unique');
            });
        }
    }

    private function tryDropUnique(string $table, array $columns): void
    {
        try {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                $blueprint->dropUnique($columns);
            });
        } catch (\Throwable) {
            // ایندکس قبلاً حذف شده یا نام متفاوت است
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bot_messages')) {
            Schema::table('bot_messages', function (Blueprint $table): void {
                $table->dropUnique('bot_messages_instance_key_unique');
                $table->unique('key');
            });
        }

        if (Schema::hasTable('settings')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropUnique('settings_instance_key_unique');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropUnique('users_instance_email_unique');
                $table->dropUnique('users_instance_telegram_unique');
            });
        }

        if (Schema::hasTable('service_shares')) {
            Schema::table('service_shares', function (Blueprint $table): void {
                $table->dropIndex(['source_instance_id']);
                $table->dropColumn('source_instance_id');
            });
        }

        foreach (array_reverse($this->tenantTables) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'instance_id')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropIndex(['instance_id']);
                    $blueprint->dropColumn('instance_id');
                });
            }
        }
    }
};
