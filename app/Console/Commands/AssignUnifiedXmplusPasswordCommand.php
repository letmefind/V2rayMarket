<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\User;
use App\Services\XmplusProvisioningService;
use App\Support\LegacyAppKeyDecryptor;
use Illuminate\Console\Command;

class AssignUnifiedXmplusPasswordCommand extends Command
{
    protected $signature = 'xmplus:assign-unified-password
                            {--password= : رمز یکسان (یا env XMPLUS_UNIFIED_PASSWORD)}
                            {--instance= : instance_id؛ پیش‌فرض APP_INSTANCE_ID}
                            {--all-with-email : همهٔ کاربران دارای ایمیل XMPlus (نه فقط بدون رمز)}
                            {--verify : بعد از ذخیره با Client API تست account/info}
                            {--export= : مسیر فایل لیست ایمیل‌ها برای پنل XMPlus}
                            {--dry-run : بدون ذخیره}';

    protected $description = 'تنظیم یک رمز یکسان در VPNMarket برای کاربران بدون رمز XMPlus (همان رمز باید در پنل XMPlus هم باشد)';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: env('XMPLUS_UNIFIED_PASSWORD', ''));
        if ($password === '') {
            $password = (string) $this->secret('رمز یکسان XMPlus (همان را در پنل XMPlus هم بگذارید)');
        }
        if (strlen($password) < 8) {
            $this->error('رمز باید حداقل ۸ کاراکتر باشد.');

            return self::FAILURE;
        }

        $instance = (string) ($this->option('instance') ?: config('app.instance_id', env('APP_INSTANCE_ID', '')));
        if ($instance === '') {
            $this->error('instance_id مشخص نیست (--instance یا APP_INSTANCE_ID).');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $allWithEmail = (bool) $this->option('all-with-email');

        $users = User::query()
            ->where('instance_id', $instance)
            ->whereNotNull('xmplus_client_email')
            ->where('xmplus_client_email', '!=', '')
            ->orderBy('id')
            ->get();

        $targets = $allWithEmail
            ? $users
            : $users->filter(fn (User $user) => $this->userNeedsUnifiedPassword($user));

        if ($targets->isEmpty()) {
            $this->warn('هیچ کاربری برای به‌روزرسانی پیدا نشد.');

            return self::SUCCESS;
        }

        $exportPath = $this->option('export');
        if (! is_string($exportPath) || $exportPath === '') {
            $exportPath = storage_path('app/xmplus-unified-password-emails-'.date('Y-m-d_His').'.txt');
        }

        $this->info('instance: '.$instance);
        $this->info('targets: '.$targets->count().' / '.$users->count());
        $this->line('export: '.$exportPath);
        if ($dryRun) {
            $this->warn('dry-run — چیزی ذخیره نمی‌شود');
        }

        $emails = [];
        $updated = 0;

        foreach ($targets as $user) {
            $email = trim((string) $user->xmplus_client_email);
            $emails[] = $email;

            if ($dryRun) {
                $this->line("would set #{$user->id} {$email}");

                continue;
            }

            $user->forceFill(['xmplus_client_password' => $password])->save();
            $updated++;
        }

        if (! $dryRun) {
            file_put_contents($exportPath, implode("\n", $emails)."\n");
            $this->info("VPNMarket updated: {$updated}");
        }

        if ($this->option('verify') && ! $dryRun) {
            $this->verifyAgainstXmplus($targets, $password);
        }

        $this->newLine();
        $this->comment('مرحلهٔ ضروری: در Admin XMPlus برای هر ایمیل export‌شده همان رمز را تنظیم کنید.');
        $this->comment('بعد از همگام‌سازی، ربات خرید/تمدید و منوی «دسترسی به پنل» رمز را درست نشان می‌دهد.');

        return self::SUCCESS;
    }

    protected function userNeedsUnifiedPassword(User $user): bool
    {
        if (trim((string) ($user->xmplus_client_password ?? '')) !== '') {
            return false;
        }

        $raw = $user->getRawOriginal('xmplus_client_password');
        if (! is_string($raw) || trim($raw) === '') {
            return true;
        }

        return LegacyAppKeyDecryptor::looksLikeLaravelEncryptedPayload($raw);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     */
    protected function verifyAgainstXmplus($users, string $password): void
    {
        $settings = Setting::query()->pluck('value', 'key');
        try {
            $api = XmplusProvisioningService::fromSettings($settings);
        } catch (\Throwable $e) {
            $this->error('XMPlus API: '.$e->getMessage());

            return;
        }

        $ok = 0;
        $fail = 0;

        foreach ($users as $user) {
            $email = trim((string) $user->xmplus_client_email);
            if ($email === '') {
                continue;
            }
            try {
                $row = $api->accountInfo($email, $password);
                if ($this->apiOk($row)) {
                    $ok++;
                    $this->line("OK  #{$user->id} {$email}");
                } else {
                    $fail++;
                    $this->warn("FAIL #{$user->id} {$email} — ".($row['message'] ?? json_encode($row)));
                }
            } catch (\Throwable $e) {
                $fail++;
                $this->warn("FAIL #{$user->id} {$email} — ".$e->getMessage());
            }
        }

        $this->info("verify: ok={$ok} fail={$fail}");
        if ($fail > 0) {
            $this->warn('برای FAIL هنوز رمز در XMPlus با VPNMarket یکسان نیست.');
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function apiOk(array $row): bool
    {
        $st = strtolower((string) ($row['status'] ?? ''));
        $code = (int) ($row['code'] ?? 0);

        return $st === 'success' || $code === 100;
    }
}
