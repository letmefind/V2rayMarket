<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class BotMessage extends Model
{
    protected $fillable = [
        'key',
        'category',
        'title',
        'content',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * دریافت متن پیام بر اساس کلید
     * 
     * @param string $key کلید پیام
     * @param string $default متن پیش‌فرض اگر پیام یافت نشد
     * @param array $variables متغیرهای جایگزینی مثل ['order_id' => 123, 'amount' => '1000000']
     * @return string
     */
    public static function get(string $key, string $default = '', array $variables = []): string
    {
        $cacheKey = 'bot_message_' . $key;
        
        $message = Cache::remember($cacheKey, now()->addHours(24), function () use ($key, $default) {
            $botMessage = self::where('key', $key)
                ->where('is_active', true)
                ->first();
            
            return $botMessage ? $botMessage->content : $default;
        });

        // جایگزینی متغیرها
        if (!empty($variables)) {
            foreach ($variables as $var => $value) {
                $message = str_replace('{' . $var . '}', (string) $value, $message);
            }
        }

        return $message;
    }

    /**
     * پاک کردن کش تمام پیام‌ها
     */
    public static function clearCache(): void
    {
        // پاک کردن کش‌های مربوط به bot_message
        $keys = self::pluck('key');
        foreach ($keys as $key) {
            Cache::forget('bot_message_' . $key);
        }
    }

    /**
     * Event listener برای پاک کردن کش بعد از ذخیره
     */
    protected static function booted(): void
    {
        static::saved(function () {
            self::clearCache();
        });

        static::deleted(function () {
            self::clearCache();
        });
    }
}
