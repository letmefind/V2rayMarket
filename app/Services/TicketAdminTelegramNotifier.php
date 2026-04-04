<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\AdminTicketCallback;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Ticketing\Models\Ticket;
use Modules\Ticketing\Models\TicketReply;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Keyboard\Keyboard;
use Telegram\Bot\Laravel\Facades\Telegram;

final class TicketAdminTelegramNotifier
{
    public static function notifyTicketOpened(Ticket $ticket): void
    {
        $ticket->loadMissing('user', 'replies');
        $firstReply = $ticket->replies->sortBy('id')->first();
        $body = self::buildTicketSummary($ticket, 'تیکت جدید', $firstReply?->message ?? $ticket->message);
        self::sendToAdmin($body, $ticket, $firstReply);
    }

    public static function notifyCustomerFollowUp(TicketReply $reply): void
    {
        $reply->loadMissing('ticket.user');
        $ticket = $reply->ticket;
        if (! $ticket) {
            return;
        }
        $body = self::buildTicketSummary($ticket, 'پیام جدید از کاربر', $reply->message);
        self::sendToAdmin($body, $ticket, $reply);
    }

    private static function buildTicketSummary(Ticket $ticket, string $head, string $messageText): string
    {
        $u = $ticket->user;
        $src = $ticket->source ?? 'web';
        $msg = Str::limit(trim($messageText), 3500, '…');

        return "📩 {$head}\n"
            ."#{$ticket->id} — {$ticket->subject}\n"
            .'کاربر: '.($u->name ?? '—')." ({$ticket->user_id})\n"
            ."منبع: {$src}\n"
            ."وضعیت: {$ticket->status}\n\n"
            ."💬 {$msg}";
    }

    private static function sendToAdmin(string $text, Ticket $ticket, ?TicketReply $replyWithAttachment): void
    {
        $settings = Setting::all()->pluck('value', 'key');
        $token = $settings->get('telegram_bot_token');
        $adminChatId = $settings->get('telegram_admin_chat_id');
        if (! $token || ! $adminChatId) {
            return;
        }

        try {
            Telegram::setAccessToken($token);
        } catch (\Throwable $e) {
            Log::warning('TicketAdminTelegramNotifier token: '.$e->getMessage());

            return;
        }

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '✍️ پاسخ در تلگرام', 'callback_data' => AdminTicketCallback::replyData($ticket->id)]),
            ]);

        $path = $replyWithAttachment?->attachment_path;
        try {
            if ($path && Storage::disk('public')->exists($path)) {
                $full = Storage::disk('public')->path($path);
                $mime = Storage::disk('public')->mimeType($path) ?: '';
                $payload = [
                    'chat_id' => (int) $adminChatId,
                    'caption' => Str::limit($text, 1020, '…'),
                    'reply_markup' => $keyboard,
                ];
                if (str_starts_with($mime, 'image/')) {
                    $payload['photo'] = InputFile::create($full);
                    Telegram::sendPhoto($payload);
                } else {
                    $payload['document'] = InputFile::create($full);
                    Telegram::sendDocument($payload);
                }
            } else {
                Telegram::sendMessage([
                    'chat_id' => (int) $adminChatId,
                    'text' => $text,
                    'reply_markup' => $keyboard,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('TicketAdminTelegramNotifier send: '.$e->getMessage(), ['ticket_id' => $ticket->id]);
        }
    }
}
