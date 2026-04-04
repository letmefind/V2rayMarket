<?php

namespace Modules\TelegramBot\Listeners;

use App\Services\TicketAdminTelegramNotifier;
use Modules\Ticketing\Events\TicketCreated;

class NotifyAdminTelegramOnTicketCreated
{
    public function handle(TicketCreated $event): void
    {
        TicketAdminTelegramNotifier::notifyTicketOpened($event->ticket);
    }
}
