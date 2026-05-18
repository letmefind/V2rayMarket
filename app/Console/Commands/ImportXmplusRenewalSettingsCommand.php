<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * وارد کردن JSON تنظیمات تمدید (خروجی export از سرور lab یا ربات مرجع).
 */
class ImportXmplusRenewalSettingsCommand extends Command
{
    protected $signature = 'vpnmarket:import-xmplus-renewal-settings
                            {file : مسیر فایل JSON}
                            {--to=* : APP_INSTANCE_ID مقصد؛ خالی = فقط همین کانتینر}
                            {--dry-run : فقط نمایش}';

    protected $description = 'Import XMPlus renewal settings JSON into one or more bot instances';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_readable($path)) {
            $this->error('فایل نیست یا خوانا نیست: '.$path);

            return self::FAILURE;
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['settings']) || ! is_array($data['settings'])) {
            $this->error('فرمت JSON نامعتبر — از vpnmarket:export-xmplus-renewal-settings استفاده کنید.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $settings */
        $settings = $data['settings'];
        $filtered = [];
        foreach ($settings as $key => $value) {
            $key = (string) $key;
            if ($key === 'panel_type' || str_starts_with($key, 'xmplus_')) {
                $filtered[$key] = $value;
            }
        }

        if ($filtered === []) {
            $this->error('هیچ کلید panel_type / xmplus_* در فایل نیست.');

            return self::FAILURE;
        }

        $toRaw = $this->option('to');
        $targets = [];
        foreach (is_array($toRaw) ? $toRaw : [$toRaw] as $chunk) {
            foreach (explode(',', (string) $chunk) as $id) {
                $id = trim($id);
                if ($id !== '') {
                    $targets[] = $id;
                }
            }
        }
        if ($targets === []) {
            $current = config('app.instance_id');
            if (! is_string($current) || $current === '') {
                $this->error('APP_INSTANCE_ID خالی — --to= بدهید.');

                return self::FAILURE;
            }
            $targets = [$current];
        }

        $targets = array_values(array_unique($targets));
        $dry = (bool) $this->option('dry-run');
        $source = (string) ($data['source_instance_id'] ?? 'unknown');

        $this->info('منبع JSON: '.$source.' ('.count($filtered).' کلید) → '.count($targets).' instance');

        foreach ($targets as $toId) {
            $this->newLine();
            $this->line('→ '.$toId);
            foreach ($filtered as $key => $value) {
                $display = $key === 'xmplus_invoice_db_password' ? '(hidden)' : (is_scalar($value) ? (string) $value : json_encode($value));
                $this->line('   '.$key.' = '.$display);
                if ($dry) {
                    continue;
                }
                Setting::withoutGlobalScope('instance')->updateOrCreate(
                    ['instance_id' => $toId, 'key' => (string) $key],
                    ['value' => $value]
                );
            }
        }

        if ($dry) {
            $this->warn('dry-run');
        } else {
            $this->newLine();
            $this->info('تمام — config:clear و vpnmarket:diagnose-xmplus-renewal روی هر ربات.');
        }

        return self::SUCCESS;
    }
}
