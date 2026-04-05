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

        $hostRaw = trim((string) $settings->get('xmplus_invoice_db_host', ''));
        $port = (int) ($settings->get('xmplus_invoice_db_port', 3306) ?: 3306);
        if (preg_match('#^https?://#i', $hostRaw) === 1) {
            $parsed = parse_url($hostRaw);
            if (is_array($parsed) && ! empty($parsed['port']) && (int) $parsed['port'] > 0) {
                $port = (int) $parsed['port'];
            }
        }
        $host = self::sanitizeMysqlHost($hostRaw);
        $database = trim((string) $settings->get('xmplus_invoice_db_database', ''));
        $username = trim((string) $settings->get('xmplus_invoice_db_username', ''));
        $password = (string) ($settings->get('xmplus_invoice_db_password') ?? '');
        $table = self::sanitizeTableName((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('XMPlus DB sync: host / database / username ناقص است.');
        }

        if (in_array(strtolower($host), ['http', 'https', 'tcp'], true)) {
            throw new RuntimeException(
                'XMPlus DB sync: مقدار هاست MySQL نامعتبر است («'.$host.'»). در فیلد هاست فقط نام میزبان یا IP سرور دیتابیس بگذارید، نه آدرس وب پنل.'
            );
        }

        $paidDate = now()->format('Y-m-d H:i');
        $paidAmount = self::resolvePaidAmountString($order);

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $database);

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        $sql = "UPDATE `{$table}` SET `status` = 1, `paid_date` = :paid_date, `paid_amount` = :paid_amount WHERE `inv_id` = :inv_id AND `status` = 0";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'paid_date' => $paidDate,
            'paid_amount' => $paidAmount,
            'inv_id' => $invId,
        ]);

        $affected = $stmt->rowCount();

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
