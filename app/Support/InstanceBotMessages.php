<?php

namespace App\Support;

use App\Models\BotMessage;
use App\Models\TelegramBotSetting;
use Illuminate\Support\Facades\Schema;

/**
 * پیام‌های ورود هر ربات (ذخیره در bot_messages با instance_id، نه telegram_bot_settings سراسری).
 */
final class InstanceBotMessages
{
    public const KEY_WELCOME = 'msg_welcome';

    public const KEY_START = 'msg_start';

    public static function welcomeDefault(string $userFirstName = ''): string
    {
        $name = $userFirstName !== '' ? $userFirstName : 'کاربر';

        return "🌟 خوش آمدید {$name} عزیز!\n\nبرای شروع، یکی از گزینه‌های منو را انتخاب کنید:";
    }

    public static function startDefault(): string
    {
        return 'سلام مجدد! لطفاً یک گزینه را انتخاب کنید:';
    }

    public static function get(string $key, string $default = '', array $variables = []): string
    {
        return BotMessage::get($key, $default, $variables);
    }

    /** متن برای فرم ادمین (اول bot_messages همین instance، بعد legacy سراسری). */
    public static function contentForAdmin(string $key, ?string $legacyTelegramKey = null): string
    {
        if (Schema::hasTable('bot_messages')) {
            $row = BotMessage::query()->where('key', $key)->first();
            if ($row && trim((string) $row->content) !== '') {
                return (string) $row->content;
            }
        }

        if ($legacyTelegramKey && Schema::hasTable('telegram_bot_settings')) {
            $legacy = TelegramBotSetting::query()->where('key', $legacyTelegramKey)->value('value');
            if (is_string($legacy) && trim($legacy) !== '') {
                return $legacy;
            }
        }

        return match ($key) {
            self::KEY_WELCOME => self::welcomeDefault(),
            self::KEY_START => self::startDefault(),
            default => '',
        };
    }

    public static function upsert(string $key, string $content, string $title, string $description): void
    {
        BotMessage::query()->updateOrCreate(
            ['key' => $key],
            [
                'category' => 'messages',
                'title' => $title,
                'content' => $content,
                'description' => $description,
                'is_active' => true,
            ]
        );

        BotMessage::clearCache();
    }
}
