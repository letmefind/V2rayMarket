<?php

namespace Modules\Ticketing\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\TelegramBot\Listeners\NotifyAdminTelegramOnCustomerTicketReply;
use Modules\TelegramBot\Listeners\NotifyAdminTelegramOnTicketCreated;
use Modules\TelegramBot\Listeners\SendTelegramReplyNotification;
use Modules\Ticketing\Events\TicketCreated;
use Modules\Ticketing\Events\TicketReplied;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        TicketCreated::class => [
            NotifyAdminTelegramOnTicketCreated::class,
        ],
        TicketReplied::class => [
            SendTelegramReplyNotification::class,
            NotifyAdminTelegramOnCustomerTicketReply::class,
        ],
    ];

    public function boot(): void
    {
        parent::boot();
    }
}
