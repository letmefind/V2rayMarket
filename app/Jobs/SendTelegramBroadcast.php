<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * هماهنگ‌کنندهٔ ارسال همگانی: به‌جای یک job خیلی بلند، برای هر دستهٔ کاربران یک Chunk جدا می‌فرستد
 * تا از تکرار ارسال به‌خاطر `retry_after` کوتاه‌تر از مدت اجرا (database/redis queue) جلوگیری شود.
 */
class SendTelegramBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $message;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(string $message)
    {
        $this->message = $message;
    }

    public function handle(): void
    {
        $chunkSize = 80;

        User::query()
            ->whereNotNull('telegram_chat_id')
            ->where('telegram_chat_id', '!=', '')
            ->orderBy('id')
            ->select(['id', 'telegram_chat_id'])
            ->chunkById($chunkSize, function ($users): void {
                $chatIds = $users->pluck('telegram_chat_id')
                    ->map(fn ($id) => trim((string) $id))
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if ($chatIds === []) {
                    return;
                }

                SendTelegramBroadcastChunk::dispatch($chatIds, $this->message);
            });
    }
}
