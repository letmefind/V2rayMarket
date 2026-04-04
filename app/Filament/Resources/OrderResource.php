<?php

namespace App\Filament\Resources;

use App\Actions\ApprovePendingOrderAction;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Notification as UserNotification;
use App\Services\ManualCryptoService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'سفارشات';
    protected static ?string $modelLabel = 'سفارش';
    protected static ?string $pluralModelLabel = 'سفارشات';
    protected static ?string $navigationGroup = 'مدیریت سفارشات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')->relationship('user', 'name')->label('کاربر')->disabled(),
                Forms\Components\Select::make('plan_id')->relationship('plan', 'name')->label('پلن')->disabled(),
                Forms\Components\Select::make('status')->label('وضعیت سفارش')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده'])->required(),
                Forms\Components\Textarea::make('config_details')->label('اطلاعات کانفیگ سرویس')->rows(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('card_payment_receipt')->label('رسید')->disk('public')->toggleable()->size(60)->circular()->url(fn (Order $record): ?string => $record->card_payment_receipt ? Storage::disk('public')->url($record->card_payment_receipt) : null)->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('plan.name')->label('پلن / آیتم')->default(fn (Order $record): string => $record->plan_id ? $record->plan->name : "شارژ کیف پول")->description(function (Order $record): string {
                    if ($record->renews_order_id) return " (تمدید سفارش #" . $record->renews_order_id . ")";
                    if (!$record->plan_id) return number_format($record->amount) . ' تومان';
                    return '';
                })->color(fn(Order $record) => $record->renews_order_id ? 'primary' : 'gray'),
                IconColumn::make('source')->label('منبع')->icon(fn (?string $state): string => match ($state) { 'web' => 'heroicon-o-globe-alt', 'telegram' => 'heroicon-o-paper-airplane', default => 'heroicon-o-question-mark-circle' })->color(fn (?string $state): string => match ($state) { 'web' => 'primary', 'telegram' => 'info', default => 'gray' }),
                Tables\Columns\TextColumn::make('payment_method')->label('روش پرداخت')->toggleable(isToggledHiddenByDefault: true)->formatStateUsing(fn (?string $state): string => match ($state) {
                    'manual_crypto' => 'USDT/USDC دستی',
                    'card' => 'کارت',
                    'wallet' => 'کیف پول',
                    'plisio' => 'Plisio',
                    default => $state ?? '—',
                }),
                Tables\Columns\TextColumn::make('crypto_network')->label('شبکه کریپتو')->toggleable(isToggledHiddenByDefault: true)->formatStateUsing(function (?string $state): string {
                    if (! $state) {
                        return '—';
                    }
                    return match ($state) {
                        'usdt_erc20' => 'USDT ERC20',
                        'usdt_bep20' => 'USDT BEP20',
                        'usdc_erc20' => 'USDC ERC20',
                        default => $state,
                    };
                }),
                Tables\Columns\TextColumn::make('crypto_amount_expected')
                    ->label('مقدار ارز')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(function ($state): string {
                        if ($state === null || $state === '') {
                            return '—';
                        }
                        $settings = Setting::all()->pluck('value', 'key');

                        return ManualCryptoService::formatAmountForDisplay((float) $state, $settings);
                    }),
                Tables\Columns\TextColumn::make('crypto_tx_hash')->label('TxID')->limit(24)->toggleable(isToggledHiddenByDefault: true),
                ImageColumn::make('crypto_payment_proof')->label('اثبات کریپتو')->disk('public')->toggleable(isToggledHiddenByDefault: true)->size(60)->circular()->url(fn (Order $record): ?string => $record->crypto_payment_proof ? Storage::disk('public')->url($record->crypto_payment_proof) : null)->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()->color(fn (string $state): string => match ($state) { 'pending' => 'warning', 'paid' => 'success', 'expired' => 'danger', default => 'gray' })->formatStateUsing(fn (string $state): string => match ($state) { 'pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده', default => $state }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ سفارش')->dateTime('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->label('تاریخ انقضا')->dateTime('Y-m-d')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده']),
                Tables\Filters\SelectFilter::make('source')->label('منبع')->options(['web' => 'وب‌سایت', 'telegram' => 'تلگرام']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('approve')->label('تایید و اجرا')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()->modalHeading('تایید پرداخت سفارش')->modalDescription('آیا از تایید این پرداخت اطمینان دارید؟')->visible(fn (Order $order): bool => $order->status === 'pending')
                    ->action(function (Order $order) {
                        $result = ApprovePendingOrderAction::execute($order);
                        if ($result->success) {
                            Notification::make()->title($result->title)->success()->send();
                        } else {
                            Notification::make()->title($result->title)->body($result->body ?? '')->danger()->send();
                        }
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
