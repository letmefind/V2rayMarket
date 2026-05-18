<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * خروجی JSON تنظیمات تمدید XMPlus — برای انتقال از سرور lab به production.
 */
class ExportXmplusRenewalSettingsCommand extends Command
{
    protected $signature = 'vpnmarket:export-xmplus-renewal-settings
                            {--from= : APP_INSTANCE_ID (پیش‌فرض: همین کانتینر)}
                            {--output= : مسیر فایل (پیش‌فرض: stdout)}';

    protected $description = 'Export XMPlus renewal settings as JSON (for copy to another server)';

    public function handle(): int
    {
        $from = trim((string) ($this->option('from') ?: config('app.instance_id', '')));
        if ($from === '') {
            $this->error('APP_INSTANCE_ID خالی است.');

            return self::FAILURE;
        }

        $rows = Setting::withoutGlobalScope('instance')
            ->where('instance_id', $from)
            ->whereIn('key', DiagnoseXmplusRenewalCommand::RENEWAL_SETTING_KEYS)
            ->get();

        if ($rows->isEmpty()) {
            $this->error('تنظیم renewal برای «'.$from.'» پیدا نشد. در Filament → تنظیمات تم پر کنید.');

            return self::FAILURE;
        }

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'source_instance_id' => $from,
            'settings' => [],
        ];

        foreach ($rows as $row) {
            $payload['settings'][(string) $row->key] = $row->getAttributes()['value'] ?? $row->value;
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            $this->error('JSON encode failed.');

            return self::FAILURE;
        }

        $out = trim((string) $this->option('output'));
        if ($out !== '') {
            file_put_contents($out, $json);
            $this->info('ذخیره شد: '.$out);

            return self::SUCCESS;
        }

        $this->line($json);

        return self::SUCCESS;
    }
}
