<?php

namespace App\Support;

final class AdminTicketCallback
{
    public static function sign(int $ticketId): string
    {
        return substr(hash_hmac('sha256', 'ticket:'.$ticketId, (string) config('app.key')), 0, 8);
    }

    public static function verify(int $ticketId, string $signature): bool
    {
        return hash_equals(self::sign($ticketId), $signature);
    }

    /** دکمهٔ «پاسخ» برای ادمین در تلگرام */
    public static function replyData(int $ticketId): string
    {
        return 'trep_'.$ticketId.'_'.self::sign($ticketId);
    }
}
