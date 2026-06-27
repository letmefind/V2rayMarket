<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Services\CardReceiptAdminTelegramNotifier;
use App\Services\TelegramPhotoStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class ProcessTelegramPhotoAttachmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60, 120, 300];

    public function __construct(
        public int $orderId,
        public int $userId,
        public string $fileId,
        public string $directory,
        public string $purpose = 'card_receipt',
    ) {}

    public function handle(): void
    {
        $order = Order::query()->find($this->orderId);
        $user = User::query()->find($this->userId);
        if (! $order || ! $user || $order->user_id !== $user->id || $order->status !== 'pending') {
            return;
        }

        if ($this->purpose === 'card_receipt' && $order->card_payment_receipt) {
            return;
        }
        if ($this->purpose === 'crypto_proof' && $order->crypto_payment_proof) {
            return;
        }

        $settings = Setting::all()->pluck('value', 'key');
        $token = (string) ($settings->get('telegram_bot_token') ?? '');
        if ($token === '') {
            Log::error('ProcessTelegramPhotoAttachmentJob: missing bot token', ['order_id' => $this->orderId]);

            return;
        }

        $fileName = TelegramPhotoStorageService::saveByFileId($token, $this->fileId, $this->directory, 25, 60);
        if (! $fileName) {
            throw new \RuntimeException('Telegram photo download failed for order '.$this->orderId);
        }

        if ($this->purpose === 'crypto_proof') {
            $order->update(['crypto_payment_proof' => $fileName]);
        } else {
            $order->update(['card_payment_receipt' => $fileName]);
        }

        $chatId = $user->telegram_chat_id;
        if ($chatId) {
            try {
                Telegram::setAccessToken($token);
                Telegram::sendMessage([
                    'chat_id' => (int) $chatId,
                    'text' => $this->purpose === 'crypto_proof'
                        ? '✅ تصویر تراکنش ثبت شد. پس از بررسی توسط ادمین، نتیجه اعلام می‌شود.'
                        : '✅ رسید شما با موفقیت ثبت شد. پس از بررسی توسط ادمین، نتیجه به شما اطلاع داده خواهد شد.',
                ]);
            } catch (\Throwable $e) {
                Log::warning('ProcessTelegramPhotoAttachmentJob user notify: '.$e->getMessage());
            }
        }

        if ($this->purpose === 'card_receipt') {
            CardReceiptAdminTelegramNotifier::notify($order->fresh(), $user, $fileName);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessTelegramPhotoAttachmentJob failed permanently', [
            'order_id' => $this->orderId,
            'error' => $exception->getMessage(),
        ]);

        $user = User::query()->find($this->userId);
        if (! $user?->telegram_chat_id) {
            return;
        }

        $token = Setting::all()->pluck('value', 'key')->get('telegram_bot_token');
        if (! $token) {
            return;
        }

        try {
            Telegram::setAccessToken($token);
            Telegram::sendMessage([
                'chat_id' => (int) $user->telegram_chat_id,
                'text' => '❌ ثبت رسید ناموفق بود. لطفاً دوباره عکس رسید را ارسال کنید یا با پشتیبانی تماس بگیرید.',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ProcessTelegramPhotoAttachmentJob failed notify: '.$e->getMessage());
        }
    }
}
