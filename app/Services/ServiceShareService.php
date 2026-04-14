<?php

namespace App\Services;

use App\Models\Order;
use App\Models\ServiceShare;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;

final class ServiceShareService
{
    /**
     * @throws AuthorizationException
     */
    public static function storeForUser(int $userId, string $payload, ?int $orderId = null, ?string $title = null): ServiceShare
    {
        $payload = trim($payload);
        if ($payload === '') {
            throw new \InvalidArgumentException('Empty payload');
        }

        $orderId = ($orderId !== null && $orderId > 0) ? $orderId : null;

        if ($orderId !== null) {
            if (! Order::query()->whereKey($orderId)->where('user_id', $userId)->exists()) {
                throw new AuthorizationException('Order does not belong to user.');
            }
        }

        $share = null;
        if ($orderId !== null) {
            $share = ServiceShare::query()
                ->where('user_id', $userId)
                ->where('order_id', $orderId)
                ->where('payload', $payload)
                ->first();
        }

        if (! $share) {
            return ServiceShare::create([
                'user_id' => $userId,
                'order_id' => $orderId,
                'code' => self::generateUniqueCode(),
                'title' => $title,
                'payload' => $payload,
                'last_shared_at' => now(),
            ]);
        }

        $share->update([
            'title' => $title ?? $share->title,
            'last_shared_at' => now(),
        ]);

        return $share;
    }

    public static function publicBaseUrl(): string
    {
        $override = config('services.iran_share.base_url');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, '/');
        }

        $fallback = config('services.iran_share.default_base_url');
        if (is_string($fallback) && trim($fallback) !== '') {
            return rtrim($fallback, '/');
        }

        return rtrim((string) config('app.url'), '/');
    }

    /** دامنهٔ بدون https (مثلاً bale.cyou) */
    public static function publicDisplayHost(): string
    {
        $host = parse_url(self::publicBaseUrl(), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'bale.cyou';
    }

    /** برای خواندن در تلفن: host + مسیر صفحه ورود کد (مثلاً bale.cyou/c) */
    public static function publicDisplayTypingPath(): string
    {
        return self::publicDisplayHost().'/c';
    }

    /** صفحهٔ ورود کد، بدون query */
    public static function publicPickupPathUrl(): string
    {
        return self::publicBaseUrl().'/c';
    }

    public static function publicLookupUrl(string $code): string
    {
        return self::publicBaseUrl().'/c?code='.rawurlencode($code);
    }

    public static function generateUniqueCode(): string
    {
        for ($i = 0; $i < 100; $i++) {
            $candidate = (string) random_int(10000, 99999);
            if (! ServiceShare::query()->where('code', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to generate unique 5-digit code.');
    }
}
