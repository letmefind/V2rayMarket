<?php

namespace Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource\Pages;

use Filament\Forms\Components\Repeater;
use Modules\TelegramBot\Filament\Resources\TelegramBotSettingResource;
use Filament\Resources\Pages\Page;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Form;
use App\Models\TelegramBotSetting;
use App\Support\InstanceBotMessages;
use Filament\Notifications\Notification;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;

class ManageTelegramBotSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $resource = TelegramBotSettingResource::class;
    protected static string $view = 'filament.pages.manage-settings';


    public ?string $activeTab = 'messages';
    public ?array $data = [];


    public function mount(): void
    {
        $settings = TelegramBotSetting::all()->pluck('value', 'key')->toArray();


        if (isset($settings['deposit_amounts'])) {

            $decodedAmounts = json_decode($settings['deposit_amounts'], true);
            // اطمینان از اینکه خروجی یک آرایه است
            $settings['deposit_amounts'] = is_array($decodedAmounts) ? $decodedAmounts : [];
        } else {
            $settings['deposit_amounts'] = [];
        }


        $this->currentAmounts = $settings['deposit_amounts'];

        $settings['welcome_message'] = InstanceBotMessages::contentForAdmin(
            InstanceBotMessages::KEY_WELCOME,
            'welcome_message',
        );
        $settings['start_message'] = InstanceBotMessages::contentForAdmin(
            InstanceBotMessages::KEY_START,
            'start_message',
        );

        $this->form->fill($settings);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('آموزش‌های اتصال')
                    ->description('ارسال در ربات با حالت HTML تلگرام است (پررنگ، ایتالیک، لینک، code). برای پر کردن خودکار فقط فیلدهای خالی: php artisan db:seed --class=TelegramBotTutorialSeeder')
                    ->schema([
                        Textarea::make('tutorial_android')
                            ->label('راهنمای اندروید (V2RayNG)')
                            ->rows(14)
                            ->columnSpanFull(),
                        Textarea::make('tutorial_ios')
                            ->label('راهنمای آیفون (V2Box / Streisand)')
                            ->rows(14)
                            ->columnSpanFull(),
                        Textarea::make('tutorial_windows')
                            ->label('راهنمای ویندوز (V2RayN)')
                            ->rows(14)
                            ->columnSpanFull(),
                    ])
                    ->columnSpan('full')
                    ->hidden(fn () => $this->activeTab !== 'tutorials'),


                Section::make('پیام‌های عمومی')
                    ->description('فقط برای همین ربات ذخیره می‌شود (جدول پیام‌های ربات). همان متن در منوی «پیام‌های ربات» با کلید msg_welcome و msg_start هم دیده می‌شود.')
                    ->schema([
                        Textarea::make('welcome_message')
                            ->label('پیام خوش‌آمدگویی')
                            ->rows(8)
                            ->helperText('متغیر: {userFirstName} — اولین ورود کاربر جدید'),
                        Textarea::make('start_message')
                            ->label('پیام دستور /start')
                            ->rows(4)
                            ->helperText('وقتی کاربر قبلاً ثبت‌نام کرده و دوباره /start می‌زند'),
                    ])
                    ->columnSpan('full')
                    ->hidden(fn () => $this->activeTab !== 'messages'),

//                Section::make('تنظیمات ربات')->schema([
//                    Textarea::make('bot_name')->label('نام ربات')->rows(1),
//                    Textarea::make('bot_token')->label('توکن ربات')->rows(1),
//                ])->columnSpan('full')->hidden(fn() => $this->activeTab !== 'settings'),






                Section::make('تنظیمات کیف پول')
                    ->schema([
                        Repeater::make('deposit_amounts')
                            ->label('مبلغ‌های پیش‌فرض شارژ')
                            ->addActionLabel('افزودن مبلغ جدید')
                            ->columns(1)
                            ->schema([
                                TextInput::make('amount')
                                    ->label('مبلغ (تومان)')
                                    ->required()
                                    ->numeric()
                                    ->prefix('تومان')
                                    ->minValue(1000),
                            ])
                            ->helperText('این مبالغ به صورت دکمه در ربات به کاربر نمایش داده می‌شوند.'),
                    ])
                    ->columnSpan('full')
                    ->hidden(fn () => $this->activeTab !== 'wallet'),
            ])
            ->statePath('data');
    }

    public function submit(): void
    {
        $formData = $this->form->getState();


        if (isset($formData['deposit_amounts'])) {
            $formData['deposit_amounts'] = json_encode($formData['deposit_amounts']);
        }

        if (array_key_exists('welcome_message', $formData)) {
            InstanceBotMessages::upsert(
                InstanceBotMessages::KEY_WELCOME,
                (string) ($formData['welcome_message'] ?? ''),
                'پیام: خوش‌آمدگویی کاربر جدید',
                'اولین ورود به ربات. متغیر: {userFirstName}',
            );
            unset($formData['welcome_message']);
        }

        if (array_key_exists('start_message', $formData)) {
            InstanceBotMessages::upsert(
                InstanceBotMessages::KEY_START,
                (string) ($formData['start_message'] ?? ''),
                'پیام: دستور /start (کاربر موجود)',
                'وقتی کاربر قبلاً ثبت‌نام کرده و دوباره /start می‌زند.',
            );
            unset($formData['start_message']);
        }

        foreach ($formData as $key => $value) {
            TelegramBotSetting::updateOrCreate(['key' => $key], ['value' => $value ?? '']);
        }

        Notification::make()->title('تنظیمات با موفقیت ذخیره شد.')->success()->send();

        // رفرش کردن صفحه برای نمایش تغییرات
        $this->redirect(static::getResource()::getUrl('index'));
    }

    protected function getFormActions(): array
    {
        return [
            \Filament\Actions\Action::make('save')
                ->label('ذخیره تغییرات')
                ->submit('submit'),
        ];
    }
}
