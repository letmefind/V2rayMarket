<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\XmplusCredentialRecovery;
use Illuminate\Console\Command;

class RehydrateXmplusPasswordsCommand extends Command
{
    protected $signature = 'xmplus:rehydrate-passwords
                            {--instance= : فقط instance_id مشخص}
                            {--dry-run : بدون ذخیره}';

    protected $description = 'بازگردانی xmplus_client_password از رمزنگاری قدیمی (APP_PREVIOUS_KEYS)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $instance = $this->option('instance');

        $query = User::query()
            ->whereNotNull('xmplus_client_email')
            ->where('xmplus_client_email', '!=', '')
            ->whereNotNull('xmplus_client_password');

        if (is_string($instance) && $instance !== '') {
            $query->where('instance_id', $instance);
        }

        $fixed = 0;
        $skipped = 0;

        $query->chunkById(200, function ($users) use ($dryRun, &$fixed, &$skipped) {
            foreach ($users as $user) {
                if (trim((string) ($user->xmplus_client_password ?? '')) !== '') {
                    $skipped++;

                    continue;
                }

                $raw = $user->getRawOriginal('xmplus_client_password');
                if (! is_string($raw) || trim($raw) === '') {
                    $skipped++;

                    continue;
                }

                if ($dryRun) {
                    $plain = \App\Support\LegacyAppKeyDecryptor::tryDecrypt($raw);
                    if ($plain !== null && $plain !== '') {
                        $this->line("would fix user #{$user->id} ({$user->xmplus_client_email})");
                        $fixed++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $plain = XmplusCredentialRecovery::rehydratePassword($user);
                if ($plain !== null && $plain !== '') {
                    $this->info("fixed user #{$user->id}");
                    $fixed++;
                } else {
                    $skipped++;
                }
            }
        });

        $this->info("done: fixed={$fixed} skipped={$skipped}".($dryRun ? ' (dry-run)' : ''));

        return self::SUCCESS;
    }
}
