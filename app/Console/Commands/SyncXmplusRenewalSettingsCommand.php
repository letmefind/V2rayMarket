<?php

namespace App\Console\Commands;

use App\Console\Commands\DiagnoseXmplusRenewalCommand;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * کپی تنظیمات تمدید XMPlus از یک instance (مثلاً lab که کار می‌کند) به بقیه.
 */
class SyncXmplusRenewalSettingsCommand extends Command
{
    protected $signature = 'vpnmarket:sync-xmplus-renewal-settings
                            {--from= : APP_INSTANCE_ID مبدأ (پیش‌فرض: همین کانتینر)}
                            {--to=* : APP_INSTANCE_ID مقصد (چند بار یا با کاما)}
                            {--dry-run : فقط نمایش}';

    protected $description = 'Copy XMPlus renewal settings (invoice DB sync, auto-pay gateway) between bot instances';

    public function handle(): int
    {
        $from = trim((string) ($this->option('from') ?: config('app.instance_id', '')));
        if ($from === '') {
            $this->error('مبدأ مشخص نیست — --from=vpnmarket_lab_bypax_store');

            return self::FAILURE;
        }

        $toRaw = $this->option('to');
        $targets = [];
        foreach (is_array($toRaw) ? $toRaw : [$toRaw] as $chunk) {
            foreach (explode(',', (string) $chunk) as $id) {
                $id = trim($id);
                if ($id !== '' && $id !== $from) {
                    $targets[] = $id;
                }
            }
        }
        $targets = array_values(array_unique($targets));

        if ($targets === []) {
            $this->error('مقصد بدهید: --to=vpnmarket_aof_bypax_store --to=vpnmarket_robot_bypax_store');

            return self::FAILURE;
        }

        $sourceRows = Setting::withoutGlobalScope('instance')
            ->where('instance_id', $from)
            ->where(function ($q) {
                $q->where('key', 'panel_type')
                    ->orWhere('key', 'like', 'xmplus_%');
            })
            ->orderBy('key')
            ->get();

        if ($sourceRows->isEmpty()) {
            $this->error('هیچ تنظیم XMPlus renewal برای instance مبدأ «'.$from.'» پیدا نشد.');

            return self::FAILURE;
        }

        $this->info('مبدأ: '.$from.' ('.$sourceRows->count().' کلید)');
        $dry = (bool) $this->option('dry-run');

        foreach ($targets as $toId) {
            $this->newLine();
            $this->line('→ مقصد: '.$toId);
            foreach ($sourceRows as $row) {
                $key = (string) $row->key;
                $value = $row->getAttributes()['value'] ?? $row->value;
                if ($key === 'xmplus_invoice_db_password' && $dry) {
                    $display = '(hidden)';
                } else {
                    $display = is_scalar($value) ? (string) $value : json_encode($value);
                }
                $this->line('   '.$key.' = '.$display);

                if ($dry) {
                    continue;
                }

                Setting::withoutGlobalScope('instance')->updateOrCreate(
                    ['instance_id' => $toId, 'key' => $key],
                    ['value' => $value]
                );
            }
            if (! $dry) {
                $this->info('   ذخیره شد.');
            }
        }

        if ($dry) {
            $this->warn('dry-run — چیزی نوشته نشد.');
        } else {
            $this->newLine();
            $this->info('تمام. روی هر مقصد: php artisan config:clear && vpnmarket:diagnose-xmplus-renewal');
        }

        return self::SUCCESS;
    }
}
