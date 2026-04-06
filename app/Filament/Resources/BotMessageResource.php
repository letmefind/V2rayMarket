<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BotMessageResource\Pages;
use App\Models\BotMessage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Tables\Filters\SelectFilter;

class BotMessageResource extends Resource
{
    protected static ?string $model = BotMessage::class;
    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationLabel = 'پیام‌های ربات';
    protected static ?string $pluralLabel = 'پیام‌های ربات تلگرام';
    protected static ?string $navigationGroup = 'ربات تلگرام';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('اطلاعات پیام')->schema([
                    Forms\Components\TextInput::make('key')
                        ->label('کلید (Key)')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('مثال: btn_payment_online یا msg_welcome')
                        ->disabled(fn ($record) => $record !== null)
                        ->columnSpan(1),

                    Forms\Components\Select::make('category')
                        ->label('دسته‌بندی')
                        ->options([
                            'buttons' => '🔘 دکمه‌ها',
                            'messages' => '💬 پیام‌ها',
                            'confirmations' => '✅ تاییدها',
                            'errors' => '❌ خطاها',
                            'instructions' => '📋 راهنماها',
                            'notifications' => '🔔 اعلان‌ها',
                        ])
                        ->required()
                        ->columnSpan(1),

                    Forms\Components\TextInput::make('title')
                        ->label('عنوان')
                        ->required()
                        ->helperText('عنوان فارسی برای شناسایی راحت')
                        ->columnSpanFull(),
                ])->columns(2),

                Forms\Components\Section::make('محتوا')->schema([
                    Forms\Components\Textarea::make('content')
                        ->label('متن پیام / دکمه')
                        ->required()
                        ->rows(4)
                        ->helperText('برای استفاده از متغیرها از {} استفاده کنید: {order_id}, {amount}, {plan_name}')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('description')
                        ->label('توضیحات')
                        ->rows(3)
                        ->helperText('توضیحات در مورد این پیام و متغیرهای قابل استفاده')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('فعال')
                        ->default(true)
                        ->helperText('در صورت غیرفعال بودن، متن پیش‌فرض کد استفاده می‌شود')
                        ->columnSpanFull(),
                ]),

                Forms\Components\Section::make('راهنما')->schema([
                    Forms\Components\Placeholder::make('variables_help')
                        ->label('متغیرهای رایج')
                        ->content(function () {
                            return view('filament.resources.bot-message-resource.variables-help');
                        })
                        ->columnSpanFull(),
                ])->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('key')
                    ->label('کلید')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),

                Tables\Columns\BadgeColumn::make('category')
                    ->label('دسته')
                    ->colors([
                        'primary' => 'buttons',
                        'success' => 'messages',
                        'warning' => 'confirmations',
                        'danger' => 'errors',
                        'info' => 'instructions',
                        'secondary' => 'notifications',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'buttons' => '🔘 دکمه‌ها',
                        'messages' => '💬 پیام‌ها',
                        'confirmations' => '✅ تاییدها',
                        'errors' => '❌ خطاها',
                        'instructions' => '📋 راهنماها',
                        'notifications' => '🔔 اعلان‌ها',
                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('content')
                    ->label('محتوا')
                    ->limit(50)
                    ->wrap()
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        if (strlen($state) > 50) {
                            return $state;
                        }
                        return null;
                    }),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخرین ویرایش')
                    ->dateTime('Y/m/d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label('دسته‌بندی')
                    ->options([
                        'buttons' => '🔘 دکمه‌ها',
                        'messages' => '💬 پیام‌ها',
                        'confirmations' => '✅ تاییدها',
                        'errors' => '❌ خطاها',
                        'instructions' => '📋 راهنماها',
                        'notifications' => '🔔 اعلان‌ها',
                    ]),
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('وضعیت')
                    ->placeholder('همه')
                    ->trueLabel('فعال')
                    ->falseLabel('غیرفعال'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('activate')
                        ->label('فعال‌سازی')
                        ->icon('heroicon-o-check-circle')
                        ->action(fn ($records) => $records->each->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion()
                        ->color('success'),
                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('غیرفعال‌سازی')
                        ->icon('heroicon-o-x-circle')
                        ->action(fn ($records) => $records->each->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion()
                        ->color('danger'),
                ]),
            ])
            ->defaultSort('category', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBotMessages::route('/'),
            'create' => Pages\CreateBotMessage::route('/create'),
            'edit' => Pages\EditBotMessage::route('/{record}/edit'),
        ];
    }
}
