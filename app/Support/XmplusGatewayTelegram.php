<?php

namespace App\Support;

use App\Models\Order;
use App\Services\XmplusProvisioningService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * ارسال لیست درگاه‌های XMPlus و راهنمای پرداخت در ربات تلگرام.
 *
 * @see https://docs.xmplus.dev/api/client.html#_12-gateway-lists
 */
final class XmplusGatewayTelegram
{
    public static function sendGatewayPicker(Order $order, Collection $settings): void
    {
        $token = $settings->get('telegram_bot_token');
        $chatId = $order->user?->telegram_chat_id;
        if (! $token || ! $chatId) {
            Log::warning('XmplusGatewayTelegram: missing token or chat_id', ['order_id' => $order->id]);

            return;
        }

        $ctx = Cache::get(XmplusProvisioningService::invoiceContextCacheKey($order->id));
        $gateways = is_array($ctx) ? ($ctx['gateway_options'] ?? []) : [];
        if ($gateways === []) {
            Log::warning('XmplusGatewayTelegram: empty gateway_options', ['order_id' => $order->id]);

            return;
        }

        Telegram::setAccessToken($token);

        $msg = '💳 <b>پرداخت فاکتور XMPlus</b>'."\n\n";
        $msg .= 'سفارش #'.$order->id." — یکی از درگاه‌های زیر را بزنید:\n\n";
        $msg .= '<i>پس از پرداخت موفق، لینک اشتراک ارسال می‌شود.</i>';

        $keyboard = Keyboard::make()->inline();
        foreach ($gateways as $g) {
            if (! is_array($g)) {
                continue;
            }
            $gid = (int) ($g['id'] ?? 0);
            if ($gid <= 0) {
                continue;
            }
            $label = self::truncateButtonText((string) ($g['name'] ?? ('درگاه '.$gid)));
            $keyboard->row([
                Keyboard::inlineButton([
                    'text' => $label,
                    'callback_data' => 'xmpgw_'.$order->id.'_'.$gid,
                ]),
            ]);
        }

        try {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $msg,
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard,
            ]);
        } catch (\Throwable $e) {
            Log::error('XmplusGatewayTelegram sendGatewayPicker: '.$e->getMessage(), ['order_id' => $order->id]);
        }
    }

    /**
     * پس از invoice/pay: QR یا رشتهٔ پرداخت (مثلاً لینک/کیف پول).
     *
     * @param  array<string, mixed>  $pay
     */
    public static function sendInvoicePayInstructions(array $pay, string $chatId, Collection $settings): void
    {
        $token = $settings->get('telegram_bot_token');
        if (! $token || $chatId === '') {
            return;
        }

        Telegram::setAccessToken($token);

        $qrcode = $pay['qrcode'] ?? null;
        if (is_string($qrcode) && str_starts_with($qrcode, 'data:image')) {
            $parts = explode(',', $qrcode, 2);
            $raw = base64_decode($parts[1] ?? '', true);
            if ($raw !== false && $raw !== '') {
                $tmp = tempnam(sys_get_temp_dir(), 'xmpq');
                if ($tmp !== false) {
                    file_put_contents($tmp, $raw);
                    try {
                        Telegram::sendPhoto([
                            'chat_id' => $chatId,
                            'photo' => InputFile::create($tmp, 'xmplus-pay.png'),
                            'caption' => '🔔 QR پرداخت فاکتور XMPlus — در صورت نیاز ابتدا پرداخت را انجام دهید.',
                        ]);
                    } catch (\Throwable $e) {
                        Log::warning('XmplusGatewayTelegram sendPhoto: '.$e->getMessage());
                    } finally {
                        @unlink($tmp);
                    }
                }
            }
        }

        $data = $pay['data'] ?? null;
        if (is_string($data) && $data !== '') {
            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "💳 اطلاعات پرداخت درگاه:\n<code>".htmlspecialchars($data, ENT_QUOTES, 'UTF-8').'</code>',
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Throwable $e) {
                Log::warning('XmplusGatewayTelegram sendMessage data: '.$e->getMessage());
            }
        }
    }

    public static function truncateButtonText(string $text, int $max = 64): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, max(1, $max - 1))).'…';
    }
}
