<?php

namespace App\Support;

use App\Models\Plan;
use App\Services\XmplusProvisioningService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * کش و بارگذاری لیست پکیج‌های XMPlus برای نمایش به کاربر (سایت / تلگرام).
 */
class XmplusCatalog
{
    /**
     * @return array{full: array<int, array<string, mixed>>, traffic: array<int, array<string, mixed>>, error?: string}
     */
    public static function get(Collection $settings, int $ttlSeconds = 300): array
    {
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['full' => [], 'traffic' => []];
        }

        $url = trim((string) ($settings->get('xmplus_panel_url') ?? ''));
        $key = (string) ($settings->get('xmplus_client_api_key') ?? '');
        if ($url === '' || $key === '') {
            return ['full' => [], 'traffic' => []];
        }

        $cacheKey = 'xmplus_catalog_v2_'.md5($url.'|'.$key);

        try {
            return Cache::remember($cacheKey, $ttlSeconds, function () use ($settings) {
                $api = XmplusProvisioningService::fromSettings($settings);
                $fullResp = $api->listFullPackages();
                $trafficResp = $api->listTrafficPackages();

                return [
                    'full' => self::normalizePackagesFromResponse(is_array($fullResp) ? $fullResp : []),
                    'traffic' => self::normalizePackagesFromResponse(is_array($trafficResp) ? $trafficResp : []),
                ];
            });
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('xmplus')->warning('XmplusCatalog::get: '.$e->getMessage());

            return ['full' => [], 'traffic' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * API پاسخی شبیه { status, code, packages: [...] } برمی‌گرداند؛ این تابع فقط آرایهٔ پکیج‌ها را برمی‌گرداند.
     *
     * @param  array<string, mixed>  $response
     * @return array<int, array<string, mixed>>
     */
    public static function normalizePackagesFromResponse(array $response): array
    {
        $list = $response['packages'] ?? null;
        if (! is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $p) {
            if (is_array($p) && isset($p['id'])) {
                $out[] = $p;
            }
        }

        return $out;
    }

    /**
     * @param  array{full?: array<int, mixed>, traffic?: array<int, mixed>}  $catalog
     * @return array<int, array<string, mixed>>
     */
    public static function packagesByPid(array $catalog): array
    {
        $byPid = [];
        foreach (array_merge($catalog['full'] ?? [], $catalog['traffic'] ?? []) as $p) {
            if (is_array($p) && isset($p['id'])) {
                $byPid[(int) $p['id']] = $p;
            }
        }

        return $byPid;
    }

    /**
     * نام نمایشی پلن در تلگرام/فاکتور: برای XMPlus از نام پکیج پنل، وگرنه نام پلن فروشگاه.
     */
    public static function displayNameForVpnPlan(Plan $plan, Collection $settings): string
    {
        $shopName = trim((string) ($plan->name ?? ''));
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return $shopName !== '' ? $shopName : 'پلن';
        }
        $pid = (int) ($plan->xmplus_package_id ?? 0);
        if ($pid <= 0) {
            $pid = (int) ($settings->get('xmplus_default_package_id') ?? 0);
        }
        if ($pid <= 0) {
            return $shopName !== '' ? $shopName : 'پلن';
        }
        $catalog = self::get($settings);
        $api = self::packagesByPid($catalog)[$pid] ?? null;
        $nm = is_array($api) ? ($api['name'] ?? null) : null;
        if (is_string($nm) && trim($nm) !== '') {
            return trim($nm);
        }

        return $shopName !== '' ? $shopName : 'پلن';
    }

    /**
     * تبدیل مقدار billing (آرایه یا JSON string) به متن خوانا.
     *
     * @param  mixed  $value
     */
    public static function formatBillingValue($value): string
    {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $dec = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($dec)) {
                    $value = $dec;
                }
            }
        }
        if (! is_array($value)) {
            return is_scalar($value) ? (string) $value : '';
        }
        $price = $value['price'] ?? null;
        $days = $value['days'] ?? null;
        $parts = [];
        if ($price !== null && $price !== '') {
            $parts[] = number_format((float) $price).' تومان';
        }
        if ($days !== null && $days !== '') {
            $parts[] = (string) $days.' روزه';
        }

        return $parts !== [] ? implode('، ', $parts) : json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string, mixed>  $billing
     */
    public static function formatBillingBlock(array $billing): string
    {
        $labels = [
            'month' => 'ماهانه',
            'quater' => '۳ماهه',
            'semiannual' => '۶ماهه',
            'annual' => 'سالانه',
            'topup_traffic' => 'ترافیک',
        ];
        $chunks = [];
        foreach ($billing as $k => $v) {
            $label = $labels[$k] ?? $k;
            $chunks[] = $label.': '.self::formatBillingValue($v);
        }

        return implode(' | ', $chunks);
    }

    /**
     * متن ساده برای تلگرام (بدون Markdown تا با کاراکترهای خاص پکیج‌ها نشکند).
     *
     * @param  array{full?: array, traffic?: array}  $catalog
     */
    public static function formatPlainTextForTelegram(array $catalog, int $maxLength = 3500): string
    {
        $blocks = [];

        foreach ($catalog['full'] ?? [] as $p) {
            if (! is_array($p)) {
                continue;
            }
            $id = $p['id'] ?? '';
            $name = $p['name'] ?? '';
            $traffic = $p['traffic'] ?? '';
            $ip = $p['iplimit'] ?? '';
            $speed = $p['speedlimit'] ?? '';
            $group = $p['server_group'] ?? '';
            $stock = '';
            if (! empty($p['enable_stock'])) {
                $stock = 'موجودی: '.($p['stock_count'] ?? '?');
            }
            $bill = $p['billing'] ?? [];
            $prices = '';
            if (is_array($bill) && $bill !== []) {
                $prices = self::formatBillingBlock($bill);
            }
            $line = "• pid {$id} — {$name}\n";
            $line .= "  حجم: {$traffic}";
            if ($speed !== '' && $speed !== null) {
                $line .= " | سرعت: {$speed}";
            }
            if ($ip !== '' && $ip !== null) {
                $line .= " | IP: {$ip}";
            }
            if ($group !== '' && $group !== null) {
                $line .= " | گروه: {$group}";
            }
            if ($stock !== '') {
                $line .= "\n  {$stock}";
            }
            if ($prices !== '') {
                $line .= "\n  قیمت‌ها (پنل): {$prices}";
            }
            $blocks[] = $line;
        }

        if (! empty($catalog['traffic'])) {
            $blocks[] = '— پکیج‌های ترافیک —';
            foreach ($catalog['traffic'] ?? [] as $p) {
                if (! is_array($p)) {
                    continue;
                }
                $id = $p['id'] ?? '';
                $name = $p['name'] ?? '';
                $traffic = $p['traffic'] ?? '';
                $bill = $p['billing'] ?? [];
                $pv = is_array($bill) ? self::formatBillingBlock($bill) : '';
                $blocks[] = "• pid {$id} — {$name} ({$traffic})".($pv !== '' ? "\n  {$pv}" : '');
            }
        }

        $text = implode("\n\n", $blocks);
        if ($text === '') {
            return '';
        }
        $header = "📋 پکیج‌های ثبت‌شده در پنل XMPlus:\n(خرید همان‌طور که قبل بود از پلن‌های سایت انجام می‌شود؛ pid را در ادمین روی پلن بگذارید.)\n\n";

        $fullText = $header.$text;
        if (mb_strlen($fullText) > $maxLength) {
            return mb_substr($fullText, 0, $maxLength)."\n…";
        }

        return $fullText;
    }
}
