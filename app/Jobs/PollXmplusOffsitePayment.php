<?php

namespace App\Jobs;

use App\Actions\CompleteXmplusGatewayPaymentAction;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * Poll XMPlus offsite gateways (PayPal/Stripe/...) after user is redirected.
 * If payment is completed in XMPlus, finalizes order and sends sublink.
 */
class PollXmplusOffsitePayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 5;

    public function __construct(
        protected int $orderId,
        protected string $telegramChatId
    ) {
    }

    public function handle(): void
    {
        $result = CompleteXmplusGatewayPaymentAction::finalizeTelegramAfterOffsite($this->orderId, $this->telegramChatId);
        if ($result['ok'] ?? false) {
            return;
        }

        $msg = mb_strtolower((string) ($result['message'] ?? ''));
        if (! $this->shouldRetry($msg)) {
            return;
        }

        if ($this->attempts() >= $this->tries) {
            $this->notifyStillPending();

            return;
        }

        // Try again later; gateway callbacks can be delayed.
        $this->release(60);
    }

    protected function shouldRetry(string $msg): bool
    {
        $needles = [
            'pending',
            'منقضی',
            'جلسه پرداخت',
            'هنوز',
            'تکمیل',
            'درگاه',
            'پرداخت',
            'subscribe',
        ];
        foreach ($needles as $n) {
            if ($n !== '' && str_contains($msg, $n)) {
                return true;
            }
        }

        return false;
    }

    protected function notifyStillPending(): void
    {
        try {
            $settings = Setting::all()->pluck('value', 'key');
            $token = (string) ($settings->get('telegram_bot_token') ?? '');
            if ($token === '' || $this->telegramChatId === '') {
                return;
            }
            $order = Order::find($this->orderId);
            if (! $order || $order->status !== 'pending') {
                return;
            }

            Telegram::setAccessToken($token);
            Telegram::sendMessage([
                'chat_id' => $this->telegramChatId,
                'text' => "⏳ پرداخت این سفارش هنوز در XMPlus نهایی نشده است.\nبعد از تکمیل پرداخت، دکمه «✅ پرداخت کردم، بررسی کن» را بزنید.",
            ]);
        } catch (\Throwable $e) {
            Log::warning('PollXmplusOffsitePayment notifyStillPending: '.$e->getMessage(), [
                'order_id' => $this->orderId,
            ]);
        }
    }
}

