<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use PDO;

/**
 * تمدید service بر اساس package تعریف شده در XMPlus (نه invoice)
 */
class XmplusPackageAwareRenewalService
{
    /**
     * تمدید service با دریافت اطلاعات از package XMPlus
     */
    public static function renewServiceFromPackage(
        XmplusService $api,
        string $email,
        string $password,
        int $serviceId,
        int $packageId,
        Collection $settings
    ): bool {
        try {
            // 1. دریافت اطلاعات package از XMPlus
            $packageInfo = self::fetchPackageInfo($api, $email, $password, $packageId);
            if ($packageInfo === null) {
                Log::warning('XMPlus package-aware renewal: package not found', [
                    'package_id' => $packageId,
                    'service_id' => $serviceId,
                ]);
                return false;
            }

            // 2. اتصال مستقیم به دیتابیس
            $enabled = $settings->get('xmplus_mysql_direct_enabled', 'no');
            if ($enabled !== 'yes') {
                Log::info('XMPlus package-aware renewal: DB sync disabled', [
                    'service_id' => $serviceId,
                ]);
                return false;
            }

            $pdo = self::createPdoConnection($settings);
            
            // 3. دریافت service فعلی
            $service = self::fetchService($pdo, $serviceId);
            if ($service === null) {
                Log::warning('XMPlus package-aware renewal: service not found', [
                    'service_id' => $serviceId,
                ]);
                return false;
            }

            // 4. محاسبه due_date جدید بر اساس package
            $currentDueDate = trim((string) ($service['due_date'] ?? ''));
            $validity = (int) ($packageInfo['validity'] ?? 30);
            $newDueDate = self::calculateNewDueDate($currentDueDate, $validity);

            // 5. آماده‌سازی داده‌های به‌روزرسانی
            $updateData = [
                'due_date' => $newDueDate,
                'status' => 1, // Active
            ];

            // اگر package traffic دارد، آن را هم به‌روز کن
            if (isset($packageInfo['traffic_gb']) && $packageInfo['traffic_gb'] > 0) {
                $trafficBytes = $packageInfo['traffic_gb'] * 1024 * 1024 * 1024;
                $updateData['traffic'] = $trafficBytes;
                $updateData['used'] = 0;
                $updateData['u'] = 0;
                $updateData['d'] = 0;
            }

            // 6. به‌روزرسانی service
            $updated = self::updateService($pdo, $serviceId, $updateData);

            if ($updated) {
                Log::info('XMPlus package-aware renewal: ✅ service renewed from package', [
                    'service_id' => $serviceId,
                    'package_id' => $packageId,
                    'old_due_date' => $currentDueDate,
                    'new_due_date' => $newDueDate,
                    'validity_days' => $validity,
                    'package_traffic_gb' => $packageInfo['traffic_gb'] ?? null,
                ]);
                return true;
            }

            return false;

        } catch (\Throwable $e) {
            Log::error('XMPlus package-aware renewal: exception', [
                'service_id' => $serviceId,
                'package_id' => $packageId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * دریافت اطلاعات package از XMPlus API
     */
    private static function fetchPackageInfo(
        XmplusService $api,
        string $email,
        string $password,
        int $packageId
    ): ?array {
        try {
            // فراخوانی API برای دریافت packages
            $response = $api->listPackages($email, $password);
            
            if (!isset($response['packages']) || !is_array($response['packages'])) {
                return null;
            }

            // جستجوی package با id
            foreach ($response['packages'] as $pkg) {
                if (isset($pkg['id']) && (int) $pkg['id'] === $packageId) {
                    return [
                        'id' => (int) $pkg['id'],
                        'name' => $pkg['name'] ?? '',
                        'validity' => (int) ($pkg['validity'] ?? 30),
                        'traffic_gb' => isset($pkg['traffic']) ? (int) $pkg['traffic'] : null,
                        'price' => $pkg['price'] ?? null,
                    ];
                }
            }

            return null;

        } catch (\Throwable $e) {
            Log::warning('XMPlus package-aware renewal: failed to fetch package', [
                'package_id' => $packageId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private static function createPdoConnection(Collection $settings): PDO
    {
        $host = $settings->get('xmplus_mysql_host', '');
        $port = (int) $settings->get('xmplus_mysql_port', 3306);
        $database = $settings->get('xmplus_mysql_database', '');
        $username = $settings->get('xmplus_mysql_username', '');
        $password = $settings->get('xmplus_mysql_password', '');

        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private static function fetchService(PDO $pdo, int $serviceId): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM `service` WHERE `id` = :id LIMIT 1');
        $stmt->execute(['id' => $serviceId]);
        $result = $stmt->fetch();

        return $result ?: null;
    }

    private static function updateService(PDO $pdo, int $serviceId, array $data): bool
    {
        $sets = [];
        $params = ['id' => $serviceId];

        foreach ($data as $key => $value) {
            $sets[] = "`{$key}` = :{$key}";
            $params[$key] = $value;
        }

        $sql = 'UPDATE `service` SET '.implode(', ', $sets).' WHERE `id` = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    private static function calculateNewDueDate(string $currentDueDate, int $addDays): string
    {
        try {
            $now = new \DateTime();

            if ($currentDueDate === '' || $currentDueDate === '0000-00-00' || $currentDueDate === '0000-00-00 00:00:00') {
                $base = clone $now;
            } else {
                $base = new \DateTime($currentDueDate);
                
                // اگر تاریخ در گذشته است (منقضی شده)، از امروز شروع کن
                if ($base < $now) {
                    $base = clone $now;
                }
            }

            $base->modify("+{$addDays} days");
            return $base->format('Y-m-d H:i:s');

        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s', strtotime("+{$addDays} days"));
        }
    }
}
