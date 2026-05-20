<?php

namespace App\Filament\Resources\UserResource\RelationManagers;

use App\Filament\Resources\OrderResource;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class OrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'orders';

    protected static ?string $title = 'سفارش‌ها';

    protected static ?string $modelLabel = 'سفارش';

    protected static ?string $pluralModelLabel = 'سفارش‌ها';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('شناسه سفارش')
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('وضعیت')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'pending' => 'warning',
                        'paid' => 'success',
                        'expired' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'pending' => 'در انتظار',
                        'paid' => 'پرداخت شده',
                        'expired' => 'منقضی',
                        default => $state ?? '—',
                    }),
                Tables\Columns\TextColumn::make('plan.name')
                    ->label('پلن')
                    ->placeholder('شارژ کیف پول')
                    ->description(fn (Order $record): ?string => $record->renews_order_id
                        ? 'تمدید #'.$record->renews_order_id
                        : null),
                Tables\Columns\TextColumn::make('amount')
                    ->label('مبلغ')
                    ->formatStateUsing(fn ($state): string => number_format((float) ($state ?? 0)).' تومان'),
                Tables\Columns\TextColumn::make('panel_username')
                    ->label('کاربر پنل')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('panel_client_id')
                    ->label('SID')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('source')
                    ->label('منبع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'telegram' => 'تلگرام',
                        'web' => 'وب',
                        default => $state ?? '—',
                    }),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت')
                    ->options([
                        'pending' => 'در انتظار',
                        'paid' => 'پرداخت شده',
                        'expired' => 'منقضی',
                    ]),
            ])
            ->headerActions([])
            ->actions([
                Tables\Actions\Action::make('open_order')
                    ->label('ویرایش سفارش')
                    ->icon('heroicon-o-pencil-square')
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),
            ])
            ->bulkActions([]);
    }
}
