<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use RuntimeException;

/**
 * به‌روزرسانی مستقیم ردیف فاکتور در دیتابیس متصل به XMPlus (جدول invoice، فیلد status).
 * سرور MySQL ممکن است روی ماشین جدا از پنل وب XMPlus باشد؛ فقط باید از سروری که VPNMarket روی آن اجرا می‌شود
 * به همان میزبان/پورت MySQL (فایروال، شبکهٔ خصوصی، SSH tunnel و غیره) دسترسی TCP برقرار شود.
 *
 * امنیت: کاربر MySQL جدا با GRANT فقط UPDATE (و در صورت نیاز SELECT) روی همان جدول بسازید.
 */
final class XmplusInvoiceDatabaseSyncService
{
    public static function enabled(Collection $settings): bool
    {
        return filter_var($settings->get('xmplus_invoice_db_sync_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * تست اتصال از همان منطق markInvoicePaid (بدون UPDATE).
     *
     * @return array{ok: bool, message: string}
     */
    public static function testConnection(Collection $settings): array
    {
        try {
            $pdo = self::createPdoConnection($settings);
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $table = self::sanitizeTableName((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));
            $like = $pdo->quote($table);
            $hasTable = (int) $pdo->query('SHOW TABLES LIKE '.$like)->rowCount() > 0;
            $tableNote = $hasTable
                ? 'جدول «'.$table.'» پیدا شد.'
                : 'هشدار: جدول «'.$table.'» در این دیتابیس دیده نشد؛ نام جدول را در تنظیمات بررسی کنید.';

            return [
                'ok' => true,
                'message' => 'اتصال برقرار است. نسخهٔ سرور: '.$version.'. '.$tableNote,
            ];
        } catch (\Throwable $e) {
            $hostRaw = trim((string) $settings->get('xmplus_invoice_db_host', ''));
            $port = (int) ($settings->get('xmplus_invoice_db_port', 3306) ?: 3306);
            $host = self::sanitizeMysqlHost($hostRaw);

            return [
                'ok' => false,
                'message' => self::formatMysqlConnectionFailureMessage($e->getMessage(), $host, $port),
            ];
        }
    }

    /**
     * بررسی ساختار جدول invoice و نمایش ستون‌های موجود
     *
     * @return array{ok: bool, columns: array<string>, message: string}
     */
    public static function inspectInvoiceTable(Collection $settings): array
    {
        try {
            $pdo = self::createPdoConnection($settings);
            $table = self::sanitizeTableName((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
            $columns = [];
            while ($row = $stmt->fetch()) {
                $columns[] = $row['Field'] ?? '';
            }
            
            $hasServiceId = in_array('serviceid', $columns, true) || 
                           in_array('service_id', $columns, true) ||
                           in_array('sid', $columns, true);
            
            return [
                'ok' => true,
                'columns' => $columns,
                'message' => 'جدول '.$table.' دارای '.count($columns).' ستون است. '.
                            ($hasServiceId ? '✅ ستون service پیدا شد.' : '❌ ستون service یافت نشد!'),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'columns' => [],
                'message' => 'خطا در بررسی جدول: '.$e->getMessage(),
            ];
        }
    }

    /**
     * توضیح فارسی برای خطاهای رایجٔ اتصال (فایروال، bind-address، …).
     */
    public static function formatMysqlConnectionFailureMessage(string $pdoMessage, string $host, int $port): string
    {
        $msg = trim($pdoMessage);
        $is2002 = str_contains($msg, '2002');
        $isTimeout = $is2002 && (stripos($msg, 'timed out') !== false || stripos($msg, 'timeout') !== false);
        $isRefused = $is2002 && stripos($msg, 'refused') !== false;

        if ($isTimeout) {
            return $msg."\n\n"
                .'[راهنما] Connection timed out یعنی از این سرور تا '.$host.':'.$port.' در سطح TCP به‌موقع پاسخ نمی‌آید (نه خطای رمز یا نام دیتابیس). '
                ."روی سرور دیتابیس (مثلاً Hestia):\n"
                ."• MySQL باید به آدرس قابل‌دسترس از بیرون گوش بدهد (مثلاً bind-address در MariaDB/MySQL، نه فقط 127.0.0.1 مگر با تونل).\n"
                ."• فایروال سرور دیتابیس و اگر هست Cloud Security Group باید پورت {$port} را از IP عمومی همین سرور VPNMarket باز کند.\n"
                ."• از همین ماشین تست کنید: nc -zv {$host} {$port}  یا  timeout 5 bash -c \"echo >/dev/tcp/{$host}/{$port}\" 2>&1\n";
        }

        if ($isRefused) {
            return $msg."\n\n"
                .'[راهنما] Connection refused معمولاً یعنی به آن IP رسیدید اما روی پورت '.$port.' سرویسی گوش نمی‌دهد یا فایروال رد می‌کند.';
        }

        return $msg;
    }

    /**
     * status را از ۰ به ۱ می‌برد (مطابق اسکیمای نمونهٔ invoice.sql).
     *
     * @throws RuntimeException|PDOException
     */
    public static function markInvoicePaid(Collection $settings, string $invId, ?Order $order = null): int
    {
        $invId = trim($invId);
        if ($invId === '') {
            throw new RuntimeException('XMPlus DB sync: inv_id خالی است.');
        }

        if (! self::enabled($settings)) {
            return 0;
        }

        $pdo = self::createPdoConnection($settings);
        $table = self::sanitizeTableName((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));

        $paidDate = now()->format('Y-m-d H:i');
        $paidAmount = self::resolvePaidAmountString($order);

        $affected = 0;
        foreach (['inv_id', 'invioce_id'] as $col) {
            try {
                $sql = "UPDATE `{$table}` SET `status` = 1, `paid_date` = :paid_date, `paid_amount` = :paid_amount WHERE `{$col}` = :inv_match AND `status` = 0";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    'paid_date' => $paidDate,
                    'paid_amount' => $paidAmount,
                    'inv_match' => $invId,
                ]);
                $affected = $stmt->rowCount();
                if ($affected > 0) {
                    break;
                }
            } catch (\Throwable $e) {
                // ستون در برخی نصب‌ها وجود ندارد
            }
        }

        $ctx = [
            'inv_id' => $invId,
            'table' => $table,
            'rows_affected' => $affected,
            'paid_date' => $paidDate,
            'paid_amount' => $paidAmount,
        ];
        if ($affected === 0) {
            Log::channel('xmplus')->warning('XMPlus invoice DB sync: UPDATE اجرا شد اما هیچ ردیفی تغییر نکرد (inv_id نامعتبر یا قبلاً status≠0)', $ctx);
        } else {
            Log::channel('xmplus')->info('XMPlus invoice DB sync: فاکتور در DB پنل به Paid به‌روز شد', $ctx);
        }

        return $affected;
    }

    /**
     * تنظیم serviceid در invoice تمدید (برای لینک کردن invoice به service موجود)
     *
     * @throws RuntimeException|PDOException
     */
    public static function setRenewalInvoiceServiceId(Collection $settings, string $invId, int $serviceId): int
    {
        $invId = trim($invId);
        if ($invId === '' || $serviceId <= 0) {
            throw new RuntimeException('XMPlus DB sync: inv_id یا service_id نامعتبر است.');
        }

        if (! self::enabled($settings)) {
            return 0;
        }

        $pdo = self::createPdoConnection($settings);
        $table = self::sanitizeTableName((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));

        $affected = 0;
        $columnVariants = ['serviceid', 'service_id', 'sid'];
        $invIdVariants = ['inv_id', 'invioce_id'];
        
        // امتحان کردن تمام ترکیبات ستون‌ها
        foreach ($invIdVariants as $invCol) {
            foreach ($columnVariants as $serviceCol) {
                try {
                    $sql = "UPDATE `{$table}` SET `{$serviceCol}` = :service_id WHERE `{$invCol}` = :inv_match";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        'service_id' => $serviceId,
                        'inv_match' => $invId,
                    ]);
                    $affected = $stmt->rowCount();
                    if ($affected > 0) {
                        Log::channel('xmplus')->info('XMPlus invoice DB sync: serviceid در فاکتور تمدید set شد', [
                            'inv_id' => $invId,
                            'service_id' => $serviceId,
                            'table' => $table,
                            'inv_column' => $invCol,
                            'service_column' => $serviceCol,
                        ]);
                        return $affected;
                    }
                } catch (\Throwable $e) {
                    // ستون وجود ندارد، امتحان variant بعدی
                    continue;
                }
            }
        }

        Log::channel('xmplus')->warning('XMPlus invoice DB sync: serviceid set نشد (inv_id پیدا نشد یا ستون service وجود ندارد)', [
            'inv_id' => $invId,
            'service_id' => $serviceId,
            'table' => $table,
            'tried_service_columns' => $columnVariants,
            'tried_inv_columns' => $invIdVariants,
        ]);

        return $affected;
    }

    /**
     * @throws RuntimeException|PDOException
     */
    protected static function createPdoConnection(Collection $settings): PDO
    {
        $hostRaw = trim((string) $settings->get('xmplus_invoice_db_host', ''));
        $port = (int) ($settings->get('xmplus_invoice_db_port', 3306) ?: 3306);
        if (preg_match('#^https?://#i', $hostRaw) === 1) {
            $parsed = parse_url($hostRaw);
            if (is_array($parsed) && ! empty($parsed['port']) && (int) $parsed['port'] > 0) {
                $port = (int) $parsed['port'];
            }
        }
        $host = self::sanitizeMysqlHost($hostRaw);
        [$database, $username] = self::resolveMysqlDatabaseAndUsername(
            trim((string) $settings->get('xmplus_invoice_db_database', '')),
            trim((string) $settings->get('xmplus_invoice_db_username', ''))
        );
        $password = (string) ($settings->get('xmplus_invoice_db_password') ?? '');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('XMPlus DB sync: host / database / username ناقص است.');
        }

        if (in_array(strtolower($host), ['http', 'https', 'tcp'], true)) {
            throw new RuntimeException(
                'XMPlus DB sync: مقدار هاست MySQL نامعتبر است («'.$host.'»). در فیلد هاست فقط نام میزبان یا IP سرور دیتابیس بگذارید، نه آدرس وب پنل.'
            );
        }

        $dsn = self::buildMysqlDsn($host, $port, $database);

        Log::channel('xmplus')->info('XMPlus invoice DB: تلاش اتصال TCP به MySQL (مقادیر واقعی پس از sanitize)', [
            'host_raw_setting' => $hostRaw !== '' ? $hostRaw : null,
            'host_used_in_dsn' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
        ]);

        $pdoOpts = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (extension_loaded('pdo_mysql')) {
            try {
                $connectTimeoutAttr = (new \ReflectionClassConstant(PDO::class, 'MYSQL_ATTR_CONNECT_TIMEOUT'))->getValue();
                if (is_int($connectTimeoutAttr)) {
                    $pdoOpts[$connectTimeoutAttr] = 12;
                }
            } catch (\ReflectionException) {
                // بعضی بیلدهای PHP/PDO این ثابت را ندارند؛ بدون آن به timeout پیش‌فرض mysqlnd تکیه می‌کنیم.
            }
        }

        return new PDO($dsn, $username, $password, $pdoOpts);
    }

    /**
     * نام دیتابیس با نقطه (مثل Hestia) در DSN باید در صورت نیاز encode شود.
     */
    protected static function buildMysqlDsn(string $host, int $port, string $database): string
    {
        $dbForDsn = preg_match('/^[a-zA-Z0-9_]+$/', $database) === 1
            ? $database
            : rawurlencode($database);

        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbForDsn);
    }

    /**
     * فقط نام میزبان یا IP برای PDO — اگر URL کامل paste شده باشد، host را جدا می‌کند.
     * خطای getaddrinfo for https معمولاً یعنی در فیلد هاست به‌جای mysql، آدرس https پنل گذاشته‌اید.
     */
    protected static function sanitizeMysqlHost(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $raw) === 1) {
            $parsed = parse_url($raw);
            if (! is_array($parsed)) {
                return '';
            }
            $h = isset($parsed['host']) ? trim((string) $parsed['host']) : '';

            return $h;
        }

        $raw = preg_replace('#^[a-z]+://#i', '', $raw) ?? $raw;
        if (str_contains($raw, '/')) {
            $raw = explode('/', $raw, 2)[0];
        }
        if (str_contains($raw, ':') && ! str_starts_with($raw, '[')) {
            $maybePort = strrchr($raw, ':');
            if ($maybePort !== false && ctype_digit(substr($maybePort, 1))) {
                $raw = substr($raw, 0, -strlen($maybePort));
            }
        }

        return trim($raw);
    }

    /**
     * اگر نام دیتابیس و کاربر دقیقاً یکسان باشند (مثلاً هر دو admin_web.admin_xmplus در Hestia)،
     * همان رشته بدون تفکیک استفاده می‌شود. در غیر این صورت اگر قالب user.suffix باشد و کاربر خالی یا همان پیشوند، تفکیک cPanel انجام می‌شود.
     *
     * @return array{0: string, 1: string} database, username
     *
     * @throws RuntimeException
     */
    protected static function resolveMysqlDatabaseAndUsername(string $databaseRaw, string $usernameRaw): array
    {
        $databaseRaw = trim($databaseRaw);
        $usernameRaw = trim($usernameRaw);

        if ($databaseRaw === '') {
            return ['', $usernameRaw];
        }

        // Hestia / نام یکسان در هر دو فیلد: بدون تفکیک
        if ($usernameRaw !== '' && strcasecmp($usernameRaw, $databaseRaw) === 0) {
            self::assertLiteralMysqlIdentifier($databaseRaw, 'database');
            self::assertLiteralMysqlIdentifier($usernameRaw, 'username');

            return [$databaseRaw, $usernameRaw];
        }

        if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/', $databaseRaw, $m) === 1) {
            $prefix = $m[1];
            $suffix = $m[2];
            $userEmpty = $usernameRaw === '';
            $userMatchesPrefix = strcasecmp($usernameRaw, $prefix) === 0;

            if ($userEmpty || $userMatchesPrefix) {
                Log::channel('xmplus')->info('XMPlus DB sync: قالب user_database (cPanel) به کاربر و دیتابیس تفکیک شد', [
                    'mysql_user' => $prefix,
                    'mysql_database' => $suffix,
                ]);

                return [$suffix, $prefix];
            }

            throw new RuntimeException(
                'XMPlus DB sync: نام دیتابیس «'.$databaseRaw.'» شبیه قالب cPanel است اما «کاربر MySQL» ('.$usernameRaw.') با پیشوند «'.$prefix.'» یکی نیست. '
                .'اگر در Hestia نام کاربر و دیتابیس یکی است، همان رشته را در هر دو فیلد بگذارید؛ در غیر این صورت کاربر را «'.$prefix.'» و دیتابیس را «'.$suffix.'» وارد کنید.'
            );
        }

        if (str_contains($databaseRaw, '/') || str_contains($databaseRaw, ' ')) {
            throw new RuntimeException(
                'XMPlus DB sync: نام دیتابیس نامعتبر است («'.$databaseRaw.'»).'
            );
        }

        if (str_contains($databaseRaw, '.')) {
            self::assertLiteralMysqlIdentifier($databaseRaw, 'database');
            if ($usernameRaw === '') {
                throw new RuntimeException('XMPlus DB sync: نام کاربر MySQL را پر کنید.');
            }
            self::assertLiteralMysqlIdentifier($usernameRaw, 'username');

            return [$databaseRaw, $usernameRaw];
        }

        if (preg_match('/^[a-zA-Z0-9_]+$/', $databaseRaw) !== 1) {
            throw new RuntimeException(
                'XMPlus DB sync: نام دیتابیس فقط باید حروف، اعداد و زیرخط باشد.'
            );
        }

        return [$databaseRaw, $usernameRaw];
    }

    /**
     * @throws RuntimeException
     */
    protected static function assertLiteralMysqlIdentifier(string $value, string $label): void
    {
        if (preg_match('/^[-0-9A-Za-z._@]+$/', $value) !== 1) {
            throw new RuntimeException(
                'XMPlus DB sync: مقدار '.$label.' نامعتبر است؛ فقط حروف، اعداد، نقطه، زیرخط، @ و - مجاز است.'
            );
        }
    }

    protected static function sanitizeTableName(string $name): string
    {
        if (preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            return 'invoice';
        }

        return $name;
    }

    protected static function resolvePaidAmountString(?Order $order): string
    {
        if (! $order) {
            return '0';
        }
        $plan = $order->plan;
        if ($plan && isset($plan->price)) {
            return (string) (int) $plan->price;
        }
        if ($order->amount !== null) {
            return (string) (int) $order->amount;
        }

        return '0';
    }
}
