<?php

namespace App\Support;

final class AdminOrderCallback
{
    public static function sign(int $orderId): string
    {
        return substr(hash_hmac('sha256', (string) $orderId, (string) config('app.key')), 0, 8);
    }

    public static function verify(int $orderId, string $signature): bool
    {
        return hash_equals(self::sign($orderId), $signature);
    }

    public static function approveData(int $orderId): string
    {
        return 'aok_'.$orderId.'_'.self::sign($orderId);
    }

    public static function cancelData(int $orderId): string
    {
        return 'acn_'.$orderId.'_'.self::sign($orderId);
    }
}
