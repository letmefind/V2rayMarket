<?php

namespace App\Filament\Resources\BotMessageResource\Pages;

use App\Filament\Resources\BotMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBotMessage extends EditRecord
{
    protected static string $resource = BotMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
