<?php

namespace App\Support;

/**
 * Telegram uses https://api.telegram.org/bot{token}/method.
 * A leading "bot" in the stored token (e.g. from copy-paste) becomes botbot... and the API returns 404 Not Found.
 */
final class TelegramBotToken
{
    public static function normalize(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            $raw = (string) ($raw[0] ?? '');
        }
        $token = trim((string) $raw);
        $token = trim($token, " \t\n\r\0\x0B\"'");

        if ($token === '') {
            return null;
        }

        if (preg_match('/^bot(\d{5,}:.+)$/i', $token, $m)) {
            return $m[1];
        }

        return $token;
    }
}
