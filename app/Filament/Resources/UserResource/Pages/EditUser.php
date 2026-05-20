<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('all_orders')
                ->label('همه سفارشات در لیست سفارشات')
                ->icon('heroicon-o-shopping-cart')
                ->color('info')
                ->url(fn (): string => OrderResource::getUrl('index', [
                    'tableFilters' => ['user_id' => ['value' => (string) $this->getRecord()->id]],
                ])),
            Actions\DeleteAction::make(),
        ];
    }
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }


    protected function getSavedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title('کاربر ویرایش شد');

    }
}
