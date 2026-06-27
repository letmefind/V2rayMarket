<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Support\AdminOrderCallback;
use App\Support\AdminTelegramUserIdentity;
use App\Support\TelegramMarkdownV2;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;

final class CardReceiptAdminTelegramNotifier
{
    public static function notify(Order $order, User $user, string $receiptPath): void
    {
        $settings = Setting::all()->pluck('value', 'key');
        $token = $settings->get('telegram_bot_token');
        $adminChatId = $settings->get('telegram_admin_chat_id');
        if (! $token || ! $adminChatId) {
            return;
        }

        $order->loadMissing('plan');
        $orderType = $order->renews_order_id ? 'تمدید سرویس' : ($order->plan_id ? 'خرید سرویس' : 'شارژ کیف پول');
        $package = self::packageMarkdownBlock($order);

        $adminMessage = "🧾 *رسید جدید برای سفارش \\#{$order->id}*\n\n";
        $adminMessage .= AdminTelegramUserIdentity::markdownBlock($user, $order);
        $adminMessage .= $package;
        $adminMessage .= '*مبلغ فیش:* '.TelegramMarkdownV2::escape(number_format((int) $order->amount).' تومان')."\n";
        $adminMessage .= '*نوع سفارش:* '.TelegramMarkdownV2::escape($orderType)."\n\n";
        $adminMessage .= TelegramMarkdownV2::escape('با دکمه‌های زیر تأیید کنید یا سفارش را لغو کنید.');

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '✅ تأیید پرداخت', 'callback_data' => AdminOrderCallback::approveData($order->id)]),
                Keyboard::inlineButton(['text' => '🗑 لغو سفارش', 'callback_data' => AdminOrderCallback::cancelData($order->id)]),
            ]);

        try {
            Telegram::setAccessToken($token);
            Telegram::sendPhoto([
                'chat_id' => (int) $adminChatId,
                'photo' => InputFile::create(Storage::disk('public')->path($receiptPath)),
                'caption' => $adminMessage,
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => $keyboard,
            ]);
        } catch (\Throwable $e) {
            Log::warning('CardReceiptAdminTelegramNotifier: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);
        }
    }

    private static function packageMarkdownBlock(Order $order): string
    {
        if (! $order->plan_id) {
            return '*پکیج:* '.TelegramMarkdownV2::escape('شارژ کیف پول')."\n"
                .'*جزئیات طرح:* '.TelegramMarkdownV2::escape(number_format((int) $order->amount).' تومان')."\n";
        }

        $plan = $order->plan;
        if (! $plan) {
            return '*پکیج:* '.TelegramMarkdownV2::escape('پلن #'.$order->plan_id)."\n";
        }

        $title = $plan->name ?? ('پلن #'.$plan->id);
        if ($order->renews_order_id) {
            $title .= ' (تمدید #'.$order->renews_order_id.')';
        }

        $detailBits = [];
        if ((int) ($plan->volume_gb ?? 0) > 0) {
            $detailBits[] = (int) $plan->volume_gb.' GB';
        }
        if ((int) ($plan->duration_days ?? 0) > 0) {
            $detailBits[] = (string) ($plan->duration_label ?? $plan->duration_days.' روز');
        }
        $detailBits[] = number_format((int) $order->amount).' تومان';

        return '*پکیج:* '.TelegramMarkdownV2::escape($title)."\n"
            .'*جزئیات طرح:* '.TelegramMarkdownV2::escape(implode(' · ', $detailBits))."\n";
    }
}
