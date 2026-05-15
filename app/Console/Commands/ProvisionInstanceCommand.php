<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionInstanceCommand extends Command
{
    protected $signature = 'vpnmarket:provision-instance
                            {--token= : Telegram bot token}
                            {--admin-email= : Filament admin email}
                            {--admin-password= : Filament admin password}
                            {--admin-name=Admin : Admin display name}
                            {--telegram-admin-chat-id= : Optional Telegram admin chat ID for notifications}';

    protected $description = 'Bootstrap instance settings and admin user after Docker deploy';

    public function handle(): int
    {
        $token = trim((string) $this->option('token'));
        if ($token !== '') {
            Setting::updateOrCreate(
                ['key' => 'telegram_bot_token'],
                ['value' => $token]
            );
            $this->info('telegram_bot_token saved.');
        }

        $adminChatId = trim((string) $this->option('telegram-admin-chat-id'));
        if ($adminChatId !== '') {
            Setting::updateOrCreate(
                ['key' => 'telegram_admin_chat_id'],
                ['value' => $adminChatId]
            );
            $this->info('telegram_admin_chat_id saved.');
        }

        $email = trim((string) $this->option('admin-email'));
        $password = (string) $this->option('admin-password');

        if ($email !== '' && $password !== '') {
            $user = User::query()->where('email', $email)->first();
            if ($user) {
                $user->update([
                    'name' => (string) $this->option('admin-name'),
                    // User::$casts['password'] === 'hashed' — خودش هش می‌کند؛ Hash::make = دوباره‌هش = ورود خراب
                    'password' => $password,
                    'is_admin' => true,
                ]);
                $this->info("Admin user updated: {$email}");
            } else {
                User::create([
                    'name' => (string) $this->option('admin-name'),
                    'email' => $email,
                    'password' => $password,
                    'is_admin' => true,
                    'referral_code' => Str::random(8),
                ]);
                $this->info("Admin user created: {$email}");
            }
        }

        return self::SUCCESS;
    }
}
