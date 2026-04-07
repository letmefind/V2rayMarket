<?php

namespace Database\Seeders;

use App\Models\TelegramBotSetting;
use App\Support\TelegramConnectionTutorials;
use Illuminate\Database\Seeder;

/**
 * پر کردن آموزش‌های اتصال ربات تلگرام (تب آموزش‌ها در Filament).
 * فقط اگر فیلد خالی باشد مقدار می‌گذارد تا متن دستی شما پاک نشود.
 *
 * اجرا: php artisan db:seed --class=TelegramBotTutorialSeeder
 */
class TelegramBotTutorialSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'tutorial_android' => TelegramConnectionTutorials::androidHtml(),
            'tutorial_ios' => TelegramConnectionTutorials::iosHtml(),
            'tutorial_windows' => TelegramConnectionTutorials::windowsHtml(),
        ];

        foreach ($defaults as $key => $html) {
            $row = TelegramBotSetting::where('key', $key)->first();
            if ($row !== null && trim((string) $row->value) !== '') {
                continue;
            }
            TelegramBotSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $html]
            );
        }

        $this->command->info('آموزش‌های اتصال تلگرام (فیلدهای خالی) به‌روز شد.');
    }
}
