<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;
use RuntimeException;

/**
 * به‌روزرسانی مستقیم ردیف فاکتور در دیتابیس پنل XMPlus (جدول invoice، فیلد status)
 * وقتی Client API فاکتور را Paid نمی‌کند ولی شما در VPNMarket پرداخت را تأیید کرده‌اید.
 *
 * امنیت: یک کاربر MySQL جدا با GRANT فقط UPDATE (و در صورت نیاز SELECT) روی همان جدول بسازید.
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

        $host = trim((string) $settings->get('xmplus_invoice_db_host', ''));
        $database = trim((string) $settings->get('xmplus_invoice_db_database', ''));
        $username = trim((string) $settings->get('xmplus_invoice_db_username', ''));
        $password = (string) ($settings->get('xmplus_invoice_db_password') ?? '');
        $port = (int) ($settings->get('xmplus_invoice_db_port', 3306) ?: 3306);
        $table = self::sanitizeTableName((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('XMPlus DB sync: host / database / username ناقص است.');
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

        Log::channel('xmplus')->info('XMPlus invoice DB sync: mark paid', [
            'inv_id' => $invId,
            'table' => $table,
            'rows' => $affected,
            'paid_date' => $paidDate,
        ]);

        return $affected;
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
