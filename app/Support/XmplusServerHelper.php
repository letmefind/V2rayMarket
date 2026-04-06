<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

class XmplusServerHelper
{
    /**
     * ذخیره اطلاعات سرورها در Cache
     */
    public static function cacheServers(int $orderId, array $servers): void
    {
        Cache::put(
            self::serversCacheKey($orderId),
            $servers,
            now()->addDays(7)
        );
    }

    /**
     * دریافت اطلاعات سرورها از Cache
     */
    public static function getCachedServers(int $orderId): ?array
    {
        return Cache::get(self::serversCacheKey($orderId));
    }

    /**
     * کلید Cache برای سرورها
     */
    protected static function serversCacheKey(int $orderId): string
    {
        return "xmplus_servers_order_{$orderId}";
    }

    /**
     * ساخت دکمه‌های Inline برای سرورها
     */
    public static function buildServerButtons(int $orderId, array $servers): array
    {
        if (empty($servers)) {
            return [];
        }

        $buttons = [];
        foreach ($servers as $index => $server) {
            $remark = $server['remark'] ?? "Server #".($index + 1);
            $buttons[] = [
                [
                    'text' => $remark,
                    'callback_data' => "server_{$orderId}_{$index}",
                ],
            ];
        }

        return $buttons;
    }

    /**
     * ساخت QR Code برای URI سرور
     */
    public static function generateQrCode(string $uri): string
    {
        $qrPath = storage_path('app/public/qrcodes');
        
        if (! file_exists($qrPath)) {
            mkdir($qrPath, 0755, true);
        }

        $filename = 'qr_'.md5($uri).'.png';
        $fullPath = $qrPath.'/'.$filename;

        QrCode::format('png')
            ->size(512)
            ->margin(2)
            ->errorCorrection('H')
            ->generate($uri, $fullPath);

        return $fullPath;
    }

    /**
     * فرمت کردن اطلاعات سرور برای نمایش
     */
    public static function formatServerInfo(array $server, int $index): string
    {
        $remark = $server['remark'] ?? "Server #".($index + 1);
        $type = strtoupper($server['type'] ?? 'UNKNOWN');
        $network = strtoupper($server['network'] ?? 'TCP');
        $security = $server['security'] ?? 'none';
        if ($security === '') {
            $security = 'none';
        }
        $security = strtoupper($security);

        $message = "🖥 <b>اطلاعات سرور</b>\n\n";
        $message .= "📌 <b>نام:</b> {$remark}\n";
        $message .= "🔧 <b>پروتکل:</b> {$type}\n";
        $message .= "🌐 <b>شبکه:</b> {$network}\n";
        $message .= "🔒 <b>امنیت:</b> {$security}\n";
        $message .= "📍 <b>آدرس:</b> <code>{$server['address']}</code>\n";
        $message .= "🔌 <b>پورت:</b> <code>{$server['port']}</code>\n\n";
        $message .= "━━━━━━━━━━━━━━━━━\n\n";
        $message .= "🔗 <b>لینک کانفیگ:</b>\n";
        $message .= "<code>{$server['uri']}</code>\n\n";
        $message .= "💡 <i>روی لینک بالا کلیک کنید تا کپی شود</i>";

        return $message;
    }

    /**
     * پاکسازی QR Code های قدیمی (فایل‌های بیش از 24 ساعت)
     */
    public static function cleanupOldQrCodes(): void
    {
        $qrPath = storage_path('app/public/qrcodes');
        
        if (! file_exists($qrPath)) {
            return;
        }

        $files = glob($qrPath.'/qr_*.png');
        $now = time();

        foreach ($files as $file) {
            if (is_file($file)) {
                if ($now - filemtime($file) >= 86400) { // 24 hours
                    @unlink($file);
                }
            }
        }
    }
}
