<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\XmplusInvoiceDatabaseSyncService;
use Illuminate\Console\Command;

/**
 * تست اتصال MySQL همگام‌سازی فاکتور XMPlus از همان محیطی که artisan اجرا می‌شود (مفید برای دیباگ tcpdump / Docker).
 */
class TestXmplusInvoiceDbCommand extends Command
{
    protected $signature = 'xmplus:test-invoice-db';

    protected $description = 'Test XMPlus invoice MySQL sync connection using saved Theme Settings';

    public function handle(): int
    {
        $settings = Setting::all()->pluck('value', 'key');
        $host = trim((string) ($settings->get('xmplus_invoice_db_host') ?? ''));

        if ($host === '') {
            $this->error('xmplus_invoice_db_host در تنظیمات (دیتابیس settings) خالی است. ابتدا در پنل ادمین ذخیره کنید.');

            return self::FAILURE;
        }

        $this->line('هاست از دیتابیس تنظیمات: '.$host);
        $this->newLine();

        $result = XmplusInvoiceDatabaseSyncService::testConnection($settings);

        if ($result['ok']) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
