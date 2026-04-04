<?php

namespace Modules\TelegramBot\Listeners;

use App\Services\TicketAdminTelegramNotifier;
use Modules\Ticketing\Events\TicketReplied;

class NotifyAdminTelegramOnCustomerTicketReply
{
    public function handle(TicketReplied $event): void
    {
        $reply = $event->reply;
        $reply->loadMissing('ticket');
        $ticket = $reply->ticket;
        if (! $ticket) {
            return;
        }

        if ((int) $reply->user_id !== (int) $ticket->user_id) {
            return;
        }

        TicketAdminTelegramNotifier::notifyCustomerFollowUp($reply);
    }
}
