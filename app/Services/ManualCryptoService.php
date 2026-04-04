<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

final class ManualCryptoService
{
    public const N_USDT_ERC20 = 'usdt_erc20';

    public const N_USDT_BEP20 = 'usdt_bep20';

    public const N_USDC_ERC20 = 'usdc_erc20';

    /** پسوند callback تلگرام → شناسه شبکه */
    public const CALLBACK_CODES = [
        'e' => self::N_USDT_ERC20,
        'b' => self::N_USDT_BEP20,
        'u' => self::N_USDC_ERC20,
    ];

    private const CONFIG = [
        self::N_USDT_ERC20 => [
            'label' => 'USDT — شبکه ERC20 (اتریوم)',
            'address_key' => 'manual_crypto_usdt_erc20_address',
        ],
        self::N_USDT_BEP20 => [
            'label' => 'USDT — شبکه BEP20 (BSC)',
            'address_key' => 'manual_crypto_usdt_bep20_address',
        ],
        self::N_USDC_ERC20 => [
            'label' => 'USDC — شبکه ERC20 (اتریوم)',
            'address_key' => 'manual_crypto_usdc_erc20_address',
        ],
    ];

    /**
     * بدون اجرای مایگریشن `2026_04_04_160000_add_manual_crypto_fields_to_orders_table` نباید گزینهٔ پرداخت دستی فعال شود.
     */
    public static function databaseReady(): bool
    {
        try {
            return Schema::hasTable('orders') && Schema::hasColumn('orders', 'crypto_network');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function isEnabled(Collection $settings): bool
    {
        if (! self::databaseReady()) {
            return false;
        }
        if (! filter_var($settings->get('manual_crypto_enabled'), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }
        foreach (array_keys(self::CONFIG) as $net) {
            if (self::networkIsReady($settings, $net)) {
                return true;
            }
        }

        return false;
    }

    public static function networkIsReady(Collection $settings, string $network): bool
    {
        if (! isset(self::CONFIG[$network])) {
            return false;
        }
        $addr = trim((string) $settings->get(self::CONFIG[$network]['address_key'], ''));
        if ($addr === '') {
            return false;
        }

        return self::rateForNetwork($settings, $network) > 0;
    }

    public static function rateForNetwork(Collection $settings, string $network): float
    {
        if ($network === self::N_USDC_ERC20) {
            $r = (float) $settings->get('manual_crypto_toman_per_usdc', 0);
            if ($r > 0) {
                return $r;
            }
        }

        return (float) $settings->get('manual_crypto_toman_per_usdt', 0);
    }

    public static function address(Collection $settings, string $network): string
    {
        $key = self::CONFIG[$network]['address_key'] ?? null;
        if (! $key) {
            return '';
        }

        return trim((string) $settings->get($key, ''));
    }

    public static function label(string $network): string
    {
        return self::CONFIG[$network]['label'] ?? $network;
    }

    /** @return array<string, string> */
    public static function availableNetworks(Collection $settings): array
    {
        $out = [];
        foreach (self::CONFIG as $id => $meta) {
            if (self::networkIsReady($settings, $id)) {
                $out[$id] = $meta['label'];
            }
        }

        return $out;
    }

    public static function expectedAmount(float $amountToman, Collection $settings, string $network): ?float
    {
        $rate = self::rateForNetwork($settings, $network);
        if ($rate <= 0) {
            return null;
        }

        return round($amountToman / $rate, self::displayDecimals($settings));
    }

    /** تعداد ارقام اعشار برای نمایش و گرد کردن مقدار ارز (۰ تا ۸). */
    public static function displayDecimals(Collection $settings): int
    {
        $raw = $settings->get('manual_crypto_display_decimals', 2);
        $n = is_numeric($raw) ? (int) $raw : 2;

        return min(8, max(0, $n));
    }

    public static function formatAmountForDisplay(float $amount, Collection $settings): string
    {
        $d = self::displayDecimals($settings);

        return number_format($amount, $d, '.', '');
    }

    public static function validateNetwork(string $network): bool
    {
        return isset(self::CONFIG[$network]);
    }

    public static function networkFromCallbackCode(string $code): ?string
    {
        return self::CALLBACK_CODES[$code] ?? null;
    }

    public static function isEnabledFromDatabase(): bool
    {
        return self::isEnabled(Setting::all()->pluck('value', 'key'));
    }
}
