<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;

/**
 * بلوک شناسایی کاربر برای اعلان‌های تلگرام ادمین (فیش، تیکت، …).
 */
final class AdminTelegramUserIdentity
{
    /**
     * @return array{
     *     bot_email: string,
     *     xmplus_email: string,
     *     service_name: string,
     *     telegram_chat_id: string,
     *     latest_paid_order_id: ?int,
     *     latest_sid: ?int
     * }
     */
    public static function parts(User $user, ?Order $order = null): array
    {
        $botEmail = trim((string) ($user->email ?? ''));
        $telegramChatId = trim((string) ($user->telegram_chat_id ?? ''));

        $xmplusEmail = trim((string) ($user->xmplus_client_email ?? ''));
        if ($xmplusEmail === '' && $order !== null) {
            $panelLogin = trim((string) ($order->panel_username ?? ''));
            if (str_contains($panelLogin, '@')) {
                $xmplusEmail = $panelLogin;
            }
        }

        $serviceName = '';
        $latestOrderId = null;
        $latestSid = null;

        if ($order !== null) {
            $serviceName = $order->serviceDisplayLabel();
            if ($order->status === 'paid') {
                $latestOrderId = (int) $order->id;
                $sid = (int) ($order->panel_client_id ?? 0);
                $latestSid = $sid > 0 ? $sid : null;
            }
        }

        if ($serviceName === '' || $xmplusEmail === '') {
            $latestPaid = $user->relationLoaded('orders')
                ? $user->orders->where('status', 'paid')->sortByDesc('id')->first()
                : $user->orders()->where('status', 'paid')->orderByDesc('id')->first();

            if ($latestPaid instanceof Order) {
                if ($serviceName === '') {
                    $serviceName = $latestPaid->serviceDisplayLabel();
                }
                $latestOrderId = (int) $latestPaid->id;
                $sid = (int) ($latestPaid->panel_client_id ?? 0);
                $latestSid = $sid > 0 ? $sid : null;
                if ($xmplusEmail === '') {
                    $pu = trim((string) ($latestPaid->panel_username ?? ''));
                    if (str_contains($pu, '@')) {
                        $xmplusEmail = $pu;
                    }
                }
            }
        }

        return [
            'bot_email' => $botEmail,
            'xmplus_email' => $xmplusEmail,
            'service_name' => $serviceName,
            'telegram_chat_id' => $telegramChatId,
            'latest_paid_order_id' => $latestOrderId,
            'latest_sid' => $latestSid,
        ];
    }

    public static function displayName(User $user): string
    {
        $displayName = trim((string) ($user->name ?? ''));
        if ($displayName === '' || $displayName === '.') {
            return '—';
        }

        return $displayName;
    }

    public static function plainBlock(User $user, ?Order $order = null): string
    {
        $parts = self::parts($user, $order);
        $lines = [
            'کاربر: '.self::displayName($user)." (ID: {$user->id})",
            'چت تلگرام: '.($parts['telegram_chat_id'] !== '' ? $parts['telegram_chat_id'] : '—'),
            'ایمیل ربات: '.($parts['bot_email'] !== '' ? $parts['bot_email'] : '—'),
            'ایمیل XMPlus: '.($parts['xmplus_email'] !== '' ? $parts['xmplus_email'] : '—'),
            'نام سرویس (انتخابی): '.($parts['service_name'] !== '' ? $parts['service_name'] : '—'),
        ];

        if ($parts['latest_paid_order_id'] !== null) {
            $orderLine = 'آخرین سفارش paid: #'.$parts['latest_paid_order_id'];
            if ($parts['latest_sid'] !== null) {
                $orderLine .= ' (sid: '.$parts['latest_sid'].')';
            }
            $lines[] = $orderLine;
        }

        return implode("\n", $lines)."\n";
    }

    public static function markdownBlock(User $user, ?Order $order = null): string
    {
        $parts = self::parts($user, $order);

        $block = '*کاربر:* '.self::escape(self::displayName($user))." \\(ID: `{$user->id}`\\)\n";
        $block .= '*چت تلگرام:* '.self::escape($parts['telegram_chat_id'] !== '' ? $parts['telegram_chat_id'] : '—')."\n";
        $block .= '*ایمیل ربات:* '.self::escape($parts['bot_email'] !== '' ? $parts['bot_email'] : '—')."\n";
        $block .= '*ایمیل XMPlus:* '.self::escape($parts['xmplus_email'] !== '' ? $parts['xmplus_email'] : '—')."\n";
        $block .= '*نام سرویس \\(انتخابی\\):* '.self::escape($parts['service_name'] !== '' ? $parts['service_name'] : '—')."\n";

        if ($parts['latest_paid_order_id'] !== null) {
            $block .= '*آخرین سفارش paid:* \\#'.$parts['latest_paid_order_id'];
            if ($parts['latest_sid'] !== null) {
                $block .= ' \\(sid: `'.$parts['latest_sid'].'`\\)';
            }
            $block .= "\n";
        }

        return $block;
    }

    public static function escape(string $text): string
    {
        $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $text = str_replace('\\', '\\\\', $text);

        return str_replace($chars, array_map(static fn (string $char): string => '\\'.$char, $chars), $text);
    }
}
