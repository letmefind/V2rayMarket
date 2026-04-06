<?php

namespace App\Filament\Resources\BotMessageResource\Pages;

use App\Filament\Resources\BotMessageResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use App\Models\BotMessage;

class ListBotMessages extends ListRecords
{
    protected static string $resource = BotMessageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\Action::make('clear_cache')
                ->label('پاک کردن کش')
                ->icon('heroicon-o-arrow-path')
                ->action(function () {
                    BotMessage::clearCache();
                    $this->notify('success', 'کش پیام‌ها با موفقیت پاک شد.');
                })
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('پاک کردن کش پیام‌ها')
                ->modalDescription('آیا مطمئن هستید؟ تمام کش‌های پیام‌های ربات پاک خواهد شد.')
                ->modalSubmitActionLabel('بله، پاک کن'),
        ];
    }

    protected function notify(string $type, string $message): void
    {
        \Filament\Notifications\Notification::make()
            ->title($message)
            ->{$type}()
            ->send();
    }
}
