<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\TelegramBot\Http\Controllers\WebhookController;

/**
 * ارسال پیام همگانی برای یک دستهٔ کوچک از chat_idها تا هر job زیر retry_after صف تمام شود
 * و ارسال تکراری به‌خاطر timeout صف رخ ندهد.
 */
class SendTelegramBroadcastChunk implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var list<string> */
    protected array $chatIds;

    protected string $message;

    public int $timeout = 300;

    public int $tries = 1;

    /**
     * @param  list<string>  $chatIds
     */
    public function __construct(array $chatIds, string $message)
    {
        $this->chatIds = array_values(array_unique(array_filter($chatIds)));
        $this->message = $message;
    }

    public function handle(): void
    {
        $controller = new WebhookController;

        foreach ($this->chatIds as $chatId) {
            $chatId = trim((string) $chatId);
            if ($chatId === '') {
                continue;
            }
            $controller->sendBroadcastMessage($chatId, $this->message);
            usleep(50000);
        }
    }
}
