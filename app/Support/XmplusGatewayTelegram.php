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

        $msg = \App\Models\BotMessage::get(
            'msg_online_gateway_picker',
            "💳 <b>پرداخت آنلاین</b>\n\nسفارش #{order_id} — یکی از درگاه‌های زیر را بزنید:\n\n<i>پس از پرداخت موفق، لینک اشتراک ارسال می‌شود.</i>",
            ['order_id' => $order->id]
        );

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
     * @param  string|null  $invid
     * @param  string|null  $panelBase
     * @param  string|null  $email
     * @param  string|null  $password
     */
    public static function sendInvoicePayInstructions(
        array $pay,
        string $chatId,
        Collection $settings,
        ?string $invid = null,
        ?string $panelBase = null,
        ?string $email = null,
        ?string $password = null
    ): void {
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
                            'caption' => '🔔 QR کد پرداخت — در صورت نیاز ابتدا پرداخت را انجام دهید.',
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
                    'text' => \App\Models\BotMessage::get(
                        'msg_payment_gateway_data',
                        "💳 اطلاعات پرداخت درگاه:\n<code>{data}</code>",
                        ['data' => htmlspecialchars($data, ENT_QUOTES, 'UTF-8')]
                    ),
                    'parse_mode' => 'HTML',
                ]);
            } catch (\Throwable $e) {
                Log::warning('XmplusGatewayTelegram sendMessage data: '.$e->getMessage());
            }
        } elseif (is_array($data) && (($data['object'] ?? '') === 'payment_intent' || isset($data['client_secret']))) {
            $invUrl = null;
            if ($invid !== null && $invid !== '' && $panelBase !== null && $panelBase !== '') {
                $invUrl = rtrim($panelBase, '/').'/portal/'.$invid.'/invoice';
            }

            $msg = \App\Models\BotMessage::get('msg_stripe_payment_header', '💳 <b>پرداخت با کارت اعتباری (Stripe)</b>')."\n\n";
            $msg .= \App\Models\BotMessage::get('msg_stripe_payment_desc', 'این درگاه نیاز به تکمیل فرم کارت در صفحه امن دارد.');
            
            if ($invUrl !== null) {
                $msg .= "\n\n".\App\Models\BotMessage::get(
                    'msg_stripe_payment_link',
                    "🔗 لینک پرداخت:\n{payment_url}",
                    ['payment_url' => '<a href="'.htmlspecialchars($invUrl, ENT_QUOTES, 'UTF-8').'">'.$invUrl.'</a>']
                );
            }
            
            if ($email !== null && $email !== '' && $password !== null && $password !== '') {
                $msg .= "\n\n".\App\Models\BotMessage::get(
                    'msg_stripe_login_info',
                    "👤 <b>اطلاعات ورود به پنل:</b>\n▫️ ایمیل: <code>{email}</code>\n▫️ رمز: <code>{password}</code>",
                    ['email' => $email, 'password' => $password]
                );
            }
            
            if ($invUrl !== null) {
                $msg .= "\n\n".\App\Models\BotMessage::get('msg_payment_complete_instruction', 'بعد از تکمیل پرداخت، دکمهٔ زیر را بزنید.');
            } else {
                $msg .= "\n\n".'لطفاً از پنل کاربری همان فاکتور را باز کنید و پرداخت را تمام کنید؛ ربات تا تأیید فاکتور منتظر می‌ماند.';
            }

            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $msg,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => false,
                ]);
            } catch (\Throwable $e) {
                Log::warning('XmplusGatewayTelegram sendMessage payment_intent: '.$e->getMessage());
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
