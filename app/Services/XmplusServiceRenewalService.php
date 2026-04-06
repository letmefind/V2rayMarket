<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PDO;
use PDOException;

/**
 * سرویس تمدید مستقیم service در دیتابیس XMPlus
 */
class XmplusServiceRenewalService
{
    /**
     * تمدید service مستقیماً در دیتابیس XMPlus
     *
     * @param Collection $settings تنظیمات XMPlus (شامل MySQL connection)
     * @param int $serviceId شناسه service که باید تمدید شود
     * @param array $invoiceData اطلاعات invoice تمدید
     * @return bool موفقیت تمدید
     */
    public static function renewServiceDirectly(Collection $settings, int $serviceId, array $invoiceData): bool
    {
        $enabled = $settings->get('xmplus_mysql_direct_enabled', 'no');
        if ($enabled !== 'yes') {
            Log::warning('XMPlus direct DB renewal: غیرفعال', [
                'service_id' => $serviceId,
            ]);
            return false;
        }

        try {
            $pdo = self::createPdoConnection($settings);
            
            // بررسی اینکه service موجود است
            $service = self::fetchService($pdo, $serviceId);
            if ($service === null) {
                Log::warning('XMPlus direct renewal: service not found', [
                    'service_id' => $serviceId,
                ]);
                return false;
            }

            // محاسبه مدت تمدید
            $addDays = self::calculateRenewalDays($invoiceData);
            if ($addDays <= 0) {
                Log::warning('XMPlus direct renewal: invalid renewal days', [
                    'service_id' => $serviceId,
                    'add_days' => $addDays,
                    'invoice' => $invoiceData,
                ]);
                return false;
            }

            // محاسبه validity و due_date جدید
            $currentValidity = (int) ($service['validity'] ?? 0);
            $newValidity = $currentValidity + $addDays;
            
            $currentDueDate = trim((string) ($service['due_date'] ?? ''));
            $newDueDate = self::calculateNewDueDate($currentDueDate, $addDays);

            // تمدید service
            $updated = self::updateService($pdo, $serviceId, [
                'validity' => $newValidity,
                'due_date' => $newDueDate,
                'status' => 1, // Active
            ]);

            if ($updated) {
                Log::info('XMPlus direct renewal: ✅ service renewed', [
                    'service_id' => $serviceId,
                    'old_validity' => $currentValidity,
                    'new_validity' => $newValidity,
                    'old_due_date' => $currentDueDate,
                    'new_due_date' => $newDueDate,
                    'added_days' => $addDays,
                ]);
                return true;
            }

            Log::warning('XMPlus direct renewal: UPDATE failed', [
                'service_id' => $serviceId,
            ]);
            return false;

        } catch (\Throwable $e) {
            Log::error('XMPlus direct renewal: exception', [
                'service_id' => $serviceId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * ایجاد اتصال PDO به دیتابیس XMPlus
     */
    private static function createPdoConnection(Collection $settings): PDO
    {
        $host = $settings->get('xmplus_mysql_host', '');
        $port = (int) $settings->get('xmplus_mysql_port', 3306);
        $databaseRaw = $settings->get('xmplus_mysql_database', '');
        $usernameRaw = $settings->get('xmplus_mysql_username', '');
        $password = $settings->get('xmplus_mysql_password', '');

        // رفع مشکل Hestia format (admin_web.admin_xmplus)
        $database = $databaseRaw;
        $username = $usernameRaw;
        
        if ($databaseRaw === $usernameRaw && str_contains($databaseRaw, '.')) {
            // Hestia format: admin_web.admin_xmplus
            Log::info('XMPlus direct renewal: Hestia format detected', [
                'mysql_user' => $username,
                'mysql_database' => $database,
            ]);
        }

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }

    /**
     * دریافت اطلاعات service
     */
    private static function fetchService(PDO $pdo, int $serviceId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM `service` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $serviceId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    /**
     * به‌روزرسانی service
     */
    private static function updateService(PDO $pdo, int $serviceId, array $data): bool
    {
        $sets = [];
        $params = ['id' => $serviceId];

        foreach ($data as $key => $value) {
            $sets[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE `service` SET ' . implode(', ', $sets) . ' WHERE `id` = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * محاسبه تعداد روزهای تمدید از invoice
     */
    private static function calculateRenewalDays(array $invoiceData): int
    {
        // از package_expire یا validity
        if (isset($invoiceData['package_expire']) && is_numeric($invoiceData['package_expire'])) {
            return (int) $invoiceData['package_expire'];
        }

        // از cycle محاسبه کن
        $cycle = strtolower(trim($invoiceData['billing'] ?? $invoiceData['cycle'] ?? ''));
        $map = [
            'month' => 30,
            'monthly' => 30,
            'quarter' => 90,
            'quarterly' => 90,
            'semiannual' => 180,
            'semi-annual' => 180,
            'annual' => 365,
            'yearly' => 365,
            'week' => 7,
            'weekly' => 7,
        ];

        return $map[$cycle] ?? 30;
    }

    /**
     * محاسبه due_date جدید
     */
    private static function calculateNewDueDate(string $currentDueDate, int $addDays): string
    {
        try {
            if ($currentDueDate === '' || $currentDueDate === '0000-00-00' || $currentDueDate === '0000-00-00 00:00:00') {
                // اگر due_date خالی است، از امروز شروع کن
                $base = new \DateTime();
            } else {
                $base = new \DateTime($currentDueDate);
            }

            $base->modify("+{$addDays} days");
            return $base->format('Y-m-d H:i:s');

        } catch (\Throwable $e) {
            // fallback
            return date('Y-m-d H:i:s', strtotime("+{$addDays} days"));
        }
    }
}
