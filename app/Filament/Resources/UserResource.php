<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Models\Order;
use App\Models\Transaction;
use Modules\TelegramBot\Http\Controllers\WebhookController;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Filament\Tables\Actions\Action;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'مدیریت کاربران';
    protected static ?string $navigationLabel = 'کاربران سایت';
    protected static ?string $pluralModelLabel = 'کاربران سایت';
    protected static ?string $modelLabel = 'کاربر';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('email')
                    ->label('ایمیل')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور جدید')
                    ->password()
                    ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $context): bool => $context === 'create')
                    ->maxLength(255),
                Forms\Components\Toggle::make('is_admin')
                    ->label('کاربر ادمین است؟'),
                
                Forms\Components\Section::make('🔐 اطلاعات پنل XMPlus')
                    ->schema([
                        Forms\Components\Placeholder::make('xmplus_info')
                            ->hiddenLabel()
                            ->content('این اطلاعات برای اتصال به XMPlus Client API استفاده می‌شود. اگر کاربر قبلاً در XMPlus register شده، password را از Admin Panel XMPlus بگیرید و اینجا ذخیره کنید.')
                            ->extraAttributes(['class' => 'text-blue-600 bg-blue-50 p-3 rounded-lg border border-blue-200']),
                        
                        Forms\Components\TextInput::make('xmplus_client_email')
                            ->label('ایمیل XMPlus')
                            ->email()
                            ->maxLength(255)
                            ->helperText('معمولاً به صورت tg{user_id}@domain است')
                            ->columnSpan(1),
                        
                        Forms\Components\TextInput::make('xmplus_client_password')
                            ->label('رمز XMPlus')
                            ->password()
                            ->revealable()
                            ->maxLength(255)
                            ->helperText('رمز Client API برای اتصال به پنل XMPlus')
                            ->columnSpan(1),
                    ])
                    ->columns(2)
                    ->collapsible()
                    ->collapsed(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('email')->label('ایمیل')->searchable(),
                Tables\Columns\IconColumn::make('is_admin')->label('ادمین')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت‌نام')->dateTime('Y-m-d')->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),

                // ✅ این دکمه در سمت راست هر سطر نمایش داده می‌شود
                Action::make('manage_subscriptions')
                    ->label('سرورها') // نام کوتاه‌تر برای دیده شدن بهتر
                    ->icon('heroicon-o-server')
                    ->color('primary')
                    ->modalHeading(fn (User $record) => "اشتراک‌های کاربر: {$record->name}")
                    ->modalWidth('6xl')
                    ->modalSubmitActionLabel('ذخیره')
                    ->visible(fn (): bool => true) // همیشه نمایش داده شود

                    // ✅ بدون هیچ شرط خاصی
                    ->mountUsing(function (Forms\ComponentContainer $form, User $record) {
                        $orders = $record->orders()
                            ->with(['plan'])
                            ->orderBy('id', 'desc')
                            ->get()
                            ->map(function ($order) {
                                return [
                                    'id' => $order->id,
                                    'panel_username' => $order->panel_username,
                                    'config_details' => $order->config_details ?? '',
                                    'expires_at' => $order->expires_at,
                                    'plan_name' => $order->plan->name ?? 'بدون پلن',
                                    'volume_gb' => $order->plan->volume_gb ?? 0,
                                    'status' => $order->status,
                                    'status_persian' => match($order->status) {
                                        'paid' => '✅ پرداخت شده',
                                        'pending' => '⏳ در انتظار',
                                        'failed' => '❌ ناموفق',
                                        default => '⚪️ نامشخص',
                                    },
                                ];
                            })
                            ->toArray();

                        $form->fill(['user_orders' => $orders]);
                    })

                    ->form([
                        Forms\Components\Section::make('⚠️ نکته امنیتی')
                            ->schema([
                                Forms\Components\Placeholder::make('warning')
                                    ->hiddenLabel()
                                    ->content('تغییرات فقط در دیتابیس سایت ذخیره می‌شود. برای تغییر در پنل X-UI/Marzban باید دستی اقدام کنید.')
                                    ->extraAttributes(['class' => 'text-red-600 bg-red-50 p-4 rounded-lg border border-red-200']),
                            ])
                            ->columnSpanFull(),

                        Forms\Components\Repeater::make('user_orders')
                            ->label('لیست سرورهای خریداری‌شده')
                            ->itemLabel(fn (array $state): ?string =>
                                ($state['status_persian'] ?? '') . ' | سفارش #' . ($state['id'] ?? '?') .
                                ' | ' . ($state['plan_name'] ?? 'بدون پلن')
                            )
                            ->addable(false)
                            ->deletable(false)
                            ->collapsible()
                            ->collapsed(false)
                            ->columns(2)
                            ->schema([
                                Forms\Components\Grid::make(4)->schema([
                                    Forms\Components\TextInput::make('id')
                                        ->label('ID سفارش')
                                        ->disabled()
                                        ->columnSpan(1),

                                    Forms\Components\TextInput::make('plan_name')
                                        ->label('نام پلن')
                                        ->disabled()
                                        ->columnSpan(1),

                                    Forms\Components\TextInput::make('status_persian')
                                        ->label('وضعیت پرداخت')
                                        ->disabled()
                                        ->columnSpan(1),

                                    Forms\Components\TextInput::make('volume_gb')
                                        ->label('حجم مجاز')
                                        ->disabled()
                                        ->suffix(' GB')
                                        ->columnSpan(1),
                                ]),

                                Forms\Components\TextInput::make('panel_username')
                                    ->label('نام کاربری پنل')
                                    ->required()
                                    ->prefixIcon('heroicon-o-user')
                                    ->helperText('این نام کاربری در پنل X-UI/Marzban باید وجود داشته باشد')
                                    ->columnSpan(1),

                                Forms\Components\DateTimePicker::make('expires_at')
                                    ->label('تاریخ انقضا سرویس')
                                    ->required()
                                    ->prefixIcon('heroicon-o-calendar')
                                    ->displayFormat('Y/m/d H:i')
                                    ->columnSpan(1),

                                Forms\Components\Section::make('🔗 لینک اتصال (کانفیگ)')
                                    ->schema([
                                        Forms\Components\Textarea::make('config_details')
                                            ->label('لینک اشتراک')
                                            ->rows(5)
                                            ->columnSpanFull()
                                            ->helperText('لینک Vless/Vmess/Trojan را اینجا ویرایش کنید. دقت کنید این لینک باید معتبر باشد!')
                                            ->required(),
                                    ])
                                    ->columnSpanFull(),
                            ]),
                    ])

                    // ذخیره تغییرات
                    ->action(function (User $record, array $data) {
                        try {
                            DB::transaction(function () use ($data) {
                                foreach ($data['user_orders'] as $orderData) {
                                    Order::where('id', $orderData['id'])->update([
                                        'panel_username' => $orderData['panel_username'],
                                        'config_details' => $orderData['config_details'],
                                        'expires_at' => $orderData['expires_at'],
                                    ]);
                                }
                            });

                            Notification::make()
                                ->title('موفقیت')
                                ->body('اطلاعات سرورها با موفقیت بروزرسانی شد.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('خطا')
                                ->body('مشکلی در ذخیره اطلاعات رخ داد.')
                                ->danger()
                                ->send();
                        }
                    }),

                // ====================================================
                // 💬 اکشن ارسال پیام تلگرام
                // ====================================================
                Action::make('send_telegram_message')
                    ->label('پیام تلگرام')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->modalHeading(fn (User $record) => 'ارسال پیام به ' . $record->name)
                    ->visible(fn (User $record): bool => (bool)$record->telegram_chat_id)
                    ->form([
                        Textarea::make('message')
                            ->label('متن پیام')
                            ->required()
                            ->rows(5)
                            ->maxLength(4096),
                    ])
                    ->action(function (User $record, array $data) {
                        $chatId = $record->telegram_chat_id;
                        if (!$chatId) {
                            Notification::make()->title('خطا')->body('کاربر Chat ID تلگرام ندارد.')->danger()->send();
                            return;
                        }
                        $webhookController = new WebhookController();
                        $success = $webhookController->sendSingleMessageToUser($chatId, $data['message']);
                        if ($success) {
                            Notification::make()->title('موفقیت')->body('پیام با موفقیت به تلگرام کاربر ارسال شد.')->success()->send();
                        } else {
                            Notification::make()->title('خطا در ارسال')->body('ارسال پیام به تلگرام ناموفق بود.')->danger()->send();
                        }
                    }),

                // ====================================================
                // 💰 اکشن تنظیم کیف پول
                // ====================================================
                Tables\Actions\Action::make('adjust_wallet')
                    ->label('تنظیم کیف پول')
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->modalHeading(fn (User $record) => "تنظیم کیف پول: {$record->name}")
                    ->modalDescription('موجودی کیف پول کاربر را افزایش یا کاهش دهید')
                    ->modalSubmitActionLabel('اعمال تغییر')
                    ->modalWidth('lg')
                    ->form([
                        Forms\Components\Placeholder::make('current_balance')
                            ->label('موجودی فعلی')
                            ->content(fn (User $record) => '💰 ' . number_format($record->balance ?? 0) . ' تومان')
                            ->columnSpanFull(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('amount')
                                    ->label('مبلغ تغییر')
                                    ->numeric()
                                    ->required()
                                    ->prefix('﷼')
                                    ->suffix('تومان')
                                    ->helperText('مثال: +100000 یا -50000')
                                    ->hint('عدد منفی برای کاهش')
                                    ->rules(['required', 'numeric', 'not_in:0'])
                                    ->live(onBlur: true),

                                Forms\Components\Placeholder::make('new_balance_preview')
                                    ->label('موجودی جدید')
                                    ->content(function (callable $get, User $record) {
                                        $amount = (int) $get('amount');
                                        if ($amount === 0 || empty($get('amount'))) return '—';
                                        $newBalance = ($record->balance ?? 0) + $amount;
                                        $emoji = $amount > 0 ? '⬆️' : '⬇️';
                                        return "{$emoji} " . number_format($newBalance) . ' تومان';
                                    }),
                            ]),

                        Forms\Components\Textarea::make('description')
                            ->label('دلیل تغییر')
                            ->required()
                            ->rows(3)
                            ->maxLength(500)
                            ->helperText('این توضیحات در تراکنش ثبت و به اطلاع کاربر می‌رسد')
                            ->placeholder('مثال: هدیه ویژه، جبران خسارت، یا تغییر دستی...'),
                    ])
                    ->action(function (User $record, array $data) {
                        $amount = (int) $data['amount'];
                        $description = $data['description'];

                        DB::transaction(function () use ($record, $amount, $description) {
                            $record->increment('balance', $amount);

                            Transaction::create([
                                'user_id' => $record->id,
                                'order_id' => null,
                                'amount' => $amount,
                                'type' => $amount > 0 ? 'deposit' : 'withdraw',
                                'status' => 'completed',
                                'description' => "تنظیم دستی توسط ادمین: {$description}",
                                'payment_method' => 'manual_admin',
                            ]);

                            if ($record->telegram_chat_id) {
                                $webhookController = new WebhookController();
                                $action = $amount >= 0 ? 'افزوده شد' : 'کسر شد';
                                $emoji = $amount > 0 ? '✅' : '⚠️';

                                $message = "{$emoji} *تغییر موجودی کیف پول*\n\n";
                                $message .= "▫️ مبلغ: *" . number_format(abs($amount)) . "* تومان {$action}\n";
                                $message .= "▫️ موجودی جدید: *" . number_format($record->balance) . "* تومان\n\n";
                                $message .= "💬 توضیحات: _{$description}_\n\n";
                                $message .= "👤 توسط: *مدیریت*";

                                $webhookController->sendSingleMessageToUser($record->telegram_chat_id, $message);
                            }
                        });

                        Notification::make()
                            ->title('موفقیت')
                            ->body("کیف پول کاربر {$record->name} با موفقیت به‌روزرسانی شد ✅")
                            ->success()
                            ->send();
                    })
                    ->requiresConfirmation()
                    ->modalIcon('heroicon-o-exclamation-triangle')
                    ->modalIconColor('warning')
                    ->modalSubmitActionLabel('بله، اعمال شود'),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
