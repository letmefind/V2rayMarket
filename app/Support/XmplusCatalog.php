<?php

namespace App\Support;

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

        $cacheKey = 'xmplus_catalog_v1_'.md5($url.'|'.$key);

        try {
            return Cache::remember($cacheKey, $ttlSeconds, function () use ($settings) {
                $api = XmplusProvisioningService::fromSettings($settings);

                return [
                    'full' => $api->listFullPackages(),
                    'traffic' => $api->listTrafficPackages(),
                ];
            });
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('xmplus')->warning('XmplusCatalog::get: '.$e->getMessage());

            return ['full' => [], 'traffic' => [], 'error' => $e->getMessage()];
        }
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
            if (is_array($bill)) {
                foreach ($bill as $bk => $bv) {
                    $prices .= ($prices === '' ? '' : ' | ').$bk.': '.(is_scalar($bv) ? (string) $bv : json_encode($bv, JSON_UNESCAPED_UNICODE));
                }
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
                $pv = '';
                if (is_array($bill) && isset($bill['topup_traffic'])) {
                    $pv = (string) $bill['topup_traffic'];
                }
                $blocks[] = "• pid {$id} — {$name} ({$traffic})".($pv !== '' ? " — {$pv}" : '');
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
