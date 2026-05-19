<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Setting;
use App\Services\XmplusInvoiceDatabaseSyncService;
use App\Support\InstanceId;
use Illuminate\Console\Command;

class DiagnoseXmplusRenewalCommand extends Command
{
    protected $signature = 'vpnmarket:diagnose-xmplus-renewal';

    protected $description = 'Check XMPlus renewal prerequisites for this bot instance (DB sync, auto-pay gateway, sample orders)';

    /** @var list<string> */
    public const RENEWAL_SETTING_KEYS = [
        'panel_type',
        'xmplus_auto_pay_gateway_id',
        'xmplus_invoice_db_sync_enabled',
        'xmplus_invoice_db_host',
        'xmplus_invoice_db_port',
        'xmplus_invoice_db_database',
        'xmplus_invoice_db_username',
        'xmplus_invoice_db_password',
        'xmplus_invoice_db_table',
    ];

    public function handle(): int
    {
        $instanceId = InstanceId::current();
        $this->info('Instance: '.$instanceId);
        $this->newLine();

        $settings = Setting::all()->pluck('value', 'key');
        $panel = (string) ($settings->get('panel_type') ?? '');
        if ($panel !== 'xmplus') {
            $this->warn('panel_type='.$panel.' — این دستور برای XMPlus است.');

            return self::SUCCESS;
        }

        $ok = true;

        $panelUrl = trim((string) ($settings->get('xmplus_panel_url') ?? ''));
        $apiKey = trim((string) ($settings->get('xmplus_client_api_key') ?? ''));
        if ($panelUrl === '' || $apiKey === '') {
            $this->error('✗ xmplus_panel_url یا xmplus_client_api_key در settings این instance خالی است — API XMPlus اصلاً نباید کار کند.');
            $ok = false;
        } else {
            $this->line('✓ xmplus_panel_url = '.$panelUrl);
            $this->line('✓ xmplus_client_api_key = (تنظیم شده، '.strlen($apiKey).' کاراکتر)');
        }

        $mysqlDirect = strtolower(trim((string) ($settings->get('xmplus_mysql_direct_enabled') ?? 'no')));
        $mysqlDirectOn = in_array($mysqlDirect, ['yes', '1', 'true'], true);
        if ($mysqlDirectOn) {
            $this->line('✓ xmplus_mysql_direct_enabled = yes (تمدید مستقیم service پس از pay)');
        } else {
            $this->line('  xmplus_mysql_direct_enabled = خیر/خالی');
        }

        $legacyMysqlHost = trim((string) ($settings->get('xmplus_mysql_host') ?? ''));
        $invoiceHost = trim((string) ($settings->get('xmplus_invoice_db_host') ?? ''));
        if ($legacyMysqlHost !== '' && $invoiceHost !== '' && $legacyMysqlHost !== $invoiceHost) {
            $this->warn('⚠ xmplus_mysql_host ('.$legacyMysqlHost.') با xmplus_invoice_db_host ('.$invoiceHost.') فرق دارد.');
            $this->line('  fallback تمدید service از invoice_db استفاده می‌کند؛ می‌توانید xmplus_mysql_host را خالی کنید یا همان 10.10.10.2 بگذارید.');
        }

        $gateway = trim((string) ($settings->get('xmplus_auto_pay_gateway_id') ?? ''));
        if ($gateway === '' || ! is_numeric($gateway)) {
            $this->error('✗ xmplus_auto_pay_gateway_id خالی است — بعد از پرداخت فاکتور تمدید بسته نمی‌شود.');
            $ok = false;
        } else {
            $this->line('✓ xmplus_auto_pay_gateway_id = '.$gateway);
        }

        $syncOn = filter_var($settings->get('xmplus_invoice_db_sync_enabled', false), FILTER_VALIDATE_BOOLEAN);
        if (! $syncOn) {
            $this->error('✗ xmplus_invoice_db_sync_enabled خاموش است — serviceid روی فاکتور تمدید set نمی‌شود (علت اصلی «فقط فاکتور Paid»).');
            $ok = false;
        } else {
            $this->line('✓ xmplus_invoice_db_sync_enabled = true');
        }

        $host = trim((string) ($settings->get('xmplus_invoice_db_host') ?? ''));
        if ($host === '') {
            $this->error('✗ xmplus_invoice_db_host خالی است.');
            $ok = false;
        } else {
            $this->line('✓ xmplus_invoice_db_host = '.$host);
            if ($syncOn) {
                $test = XmplusInvoiceDatabaseSyncService::testConnection($settings);
                if ($test['ok']) {
                    $this->line('✓ اتصال MySQL فاکتور XMPlus: '.$test['message']);
                } else {
                    $this->error('✗ اتصال MySQL فاکتور XMPlus ناموفق:');
                    $this->line($test['message']);
                    $ok = false;
                }
                if ($mysqlDirectOn && $test['ok']) {
                    try {
                        $pdo = XmplusInvoiceDatabaseSyncService::createPdoForServiceTable($settings);
                        $pdo->query('SELECT 1 FROM `service` LIMIT 1');
                        $this->line('✓ اتصال MySQL جدول service (fallback تمدید): OK');
                    } catch (\Throwable $e) {
                        $this->error('✗ اتصال MySQL جدول service برای fallback تمدید: '.$e->getMessage());
                        $ok = false;
                    }
                }
            }
        }

        $this->newLine();
        $this->line('چرا lab کار می‌کند و production نه؟');
        $this->line('  • کد یکسان است؛ تفاوت معمولاً در تنظیمات instance است (همگام‌سازی فاکتور + MySQL مستقیم service).');
        $this->line('  • API درست: service/renew (sid) → invid با serviceid → invoice/pay (gateway ShopPrepaidConfirm).');
        $this->line('  • اگر فقط invoice/create استفاده شود، serviceid خالی می‌ماند و pay سرویس را تمدید نمی‌کند.');
        $this->line('  • اگر pay موفق باشد ولی expire_date در service/info خالی بماند، پنل سرویس را تمدید نکرده (خطای PaymentSuccess یا due_date خالی در DB).');
        $this->newLine();
        $this->line('کد deploy:');
        if ($this->artisanHas('vpnmarket:sync-renew-eligibility-messages')) {
            $this->line('✓ vpnmarket:sync-renew-eligibility-messages موجود است (image به‌روز)');
        } else {
            $this->error('✗ دستور sync-renew-eligibility نیست — rebuild-instance-web لازم است.');
            $ok = false;
        }

        if (class_exists(\App\Support\XmplusRenewalEligibility::class)) {
            $this->line('✓ XmplusRenewalEligibility موجود است');
        } else {
            $this->error('✗ XmplusRenewalEligibility نیست — git pull + rebuild');
            $ok = false;
        }

        $this->newLine();
        $this->line('نمونه سفارش‌های paid با plan (۵ تای آخر):');
        $orders = Order::query()
            ->whereNotNull('plan_id')
            ->where('status', 'paid')
            ->whereNull('renews_order_id')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'panel_client_id', 'xmplus_inv_id']);

        if ($orders->isEmpty()) {
            $this->warn('  (سفارشی نیست)');
        } else {
            foreach ($orders as $o) {
                $sid = $o->panel_client_id;
                $sidOk = $sid !== null && $sid !== '' && (int) $sid > 0;
                $this->line(sprintf(
                    '  #%d  sid=%s %s',
                    $o->id,
                    $sid ?? '—',
                    $sidOk ? '✓' : '✗ (تمدید بدون sid ممکن نیست)'
                ));
                if (! $sidOk) {
                    $ok = false;
                }
            }
        }

        $this->newLine();
        $this->line('همهٔ کلیدهای xmplus_* ذخیره‌شده:');
        $allXm = Setting::query()->where('key', 'like', 'xmplus_%')->orderBy('key')->pluck('value', 'key');
        if ($allXm->isEmpty()) {
            $this->warn('  (هیچ کلید xmplus_* در settings نیست)');
        } else {
            foreach ($allXm as $k => $v) {
                if (str_contains((string) $k, 'password')) {
                    $display = ($v === null || $v === '') ? '(خالی)' : '(تنظیم شده)';
                } else {
                    $display = is_scalar($v) ? (string) $v : json_encode($v);
                }
                $this->line('  '.$k.' = '.$display);
            }
        }

        $this->newLine();
        if ($ok) {
            $this->info('جمع‌بندی: پیش‌نیازهای تمدید برای این ربات OK به نظر می‌رسد.');
            $this->line('اگر هنوز تمدید نمی‌شود: storage/logs (channel xmplus) را برای invid و serviceid ببینید.');

            return self::SUCCESS;
        }

        $this->warn('جمع‌بندی: روی **همین ربات** در Filament → تنظیمات تم:');
        $this->line('  1) همگام‌سازی MySQL فاکتور = روشن + host/user/pass جدول invoice پنل **همین** فروشگاه');
        $this->line('  2) تست اتصال MySQL');
        $this->line('lab و production سرور/DB جدا هستند — export lab فقط gateway=24 دارد؛ credentials MySQL را از **هاست پنل production** بگیرید، نه از lab.');

        return self::FAILURE;
    }

    protected function artisanHas(string $name): bool
    {
        try {
            return $this->getApplication()?->has($name) ?? false;
        } catch (\Throwable) {
            return false;
        }
    }
}
