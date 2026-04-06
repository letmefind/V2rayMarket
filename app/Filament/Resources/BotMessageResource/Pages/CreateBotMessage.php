<?php

namespace App\Filament\Resources\BotMessageResource\Pages;

use App\Filament\Resources\BotMessageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateBotMessage extends CreateRecord
{
    protected static string $resource = BotMessageResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
