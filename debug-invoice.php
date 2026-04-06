<?php

/**
 * اسکریپت debug برای بررسی invoice در دیتابیس
 * 
 * استفاده:
 * php debug-invoice.php JCDOPUBDXLE0OORM1WXD
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Setting;
use PDO;

if ($argc < 2) {
    echo "❌ استفاده: php debug-invoice.php <INV_ID>\n";
    echo "   مثال: php debug-invoice.php JCDOPUBDXLE0OORM1WXD\n";
    exit(1);
}

$invId = $argv[1];

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔍 بررسی invoice در دیتابیس XMPlus\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
echo "Invoice ID: {$invId}\n\n";

$settings = Setting::all()->pluck('value', 'key');

// اتصال به دیتابیس
$host = trim((string) $settings->get('xmplus_invoice_db_host', ''));
$port = (int) ($settings->get('xmplus_invoice_db_port', 3306) ?: 3306);
$database = trim((string) $settings->get('xmplus_invoice_db_database', ''));
$username = trim((string) $settings->get('xmplus_invoice_db_username', ''));
$password = (string) ($settings->get('xmplus_invoice_db_password') ?? '');
$table = trim((string) ($settings->get('xmplus_invoice_db_table', 'invoice') ?: 'invoice'));

// Parse database name (Hestia format: admin_web.admin_xmplus)
if (str_contains($database, '.') && $username === $database) {
    if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/', $database, $m)) {
        $username = $m[1];
        $database = $m[2];
        echo "🔧 Parse شد: user={$username}, db={$database}\n";
    }
} elseif (str_contains($database, '.') && $username === '') {
    if (preg_match('/^([a-zA-Z0-9_]+)\.([a-zA-Z0-9_]+)$/', $database, $m)) {
        $username = $m[1];
        $database = $m[2];
        echo "🔧 Parse شد: user={$username}, db={$database}\n";
    }
}

echo "📊 اطلاعات اتصال:\n";
echo "   Host: {$host}:{$port}\n";
echo "   Database: {$database}\n";
echo "   Username: {$username}\n";
echo "   Table: {$table}\n\n";

try {
    $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    
    echo "✅ اتصال به دیتابیس موفق\n\n";
    
    // بررسی ستون‌های جدول
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "1️⃣ ستون‌های جدول {$table}:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
    $columns = [];
    while ($row = $stmt->fetch()) {
        $col = $row['Field'];
        $columns[] = $col;
        $marker = (str_contains($col, 'inv') || str_contains($col, 'service')) ? '🔵' : '  ';
        echo "{$marker} {$col} ({$row['Type']})\n";
    }
    echo "\n";
    
    // جستجو با inv_id
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "2️⃣ جستجو با inv_id:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    foreach (['inv_id', 'invioce_id', 'invoice_id'] as $col) {
        if (!in_array($col, $columns, true)) {
            echo "⚠️  ستون {$col} وجود ندارد\n";
            continue;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE `{$col}` = :inv_id LIMIT 1");
            $stmt->execute(['inv_id' => $invId]);
            $row = $stmt->fetch();
            
            if ($row) {
                echo "✅ یافت شد با ستون: {$col}\n";
                echo "   ID: {$row['id']}\n";
                echo "   Created: ".($row['created_at'] ?? 'N/A')."\n";
                echo "   Status: ".($row['status'] ?? 'N/A')."\n";
                
                // نمایش تمام ستون‌های service
                foreach ($row as $key => $val) {
                    if (str_contains(strtolower($key), 'service')) {
                        echo "   {$key}: ".($val ?? 'NULL')."\n";
                    }
                }
                echo "\n";
                
                // تست UPDATE
                echo "3️⃣ تست UPDATE:\n";
                echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                
                foreach (['serviceid', 'service_id', 'sid'] as $serviceCol) {
                    if (!in_array($serviceCol, $columns, true)) {
                        echo "⚠️  ستون {$serviceCol} وجود ندارد\n";
                        continue;
                    }
                    
                    try {
                        $testSql = "UPDATE `{$table}` SET `{$serviceCol}` = :service_id WHERE `{$col}` = :inv_match";
                        echo "SQL: {$testSql}\n";
                        
                        $updateStmt = $pdo->prepare($testSql);
                        $updateStmt->execute([
                            'service_id' => 15892,
                            'inv_match' => $invId,
                        ]);
                        $affected = $updateStmt->rowCount();
                        
                        if ($affected > 0) {
                            echo "✅ UPDATE موفق! ردیف‌های تغییر یافته: {$affected}\n";
                            echo "   ستون invoice: {$col}\n";
                            echo "   ستون service: {$serviceCol}\n\n";
                            
                            // بررسی مقدار جدید
                            $checkStmt = $pdo->prepare("SELECT `{$serviceCol}` FROM `{$table}` WHERE `{$col}` = :inv_id");
                            $checkStmt->execute(['inv_id' => $invId]);
                            $newVal = $checkStmt->fetchColumn();
                            echo "   مقدار جدید: {$newVal}\n";
                            
                            exit(0);
                        } else {
                            echo "❌ UPDATE بدون تأثیر (affected = 0)\n";
                        }
                    } catch (\Throwable $e) {
                        echo "❌ خطا: ".$e->getMessage()."\n";
                    }
                }
                
                exit(0);
            } else {
                echo "❌ یافت نشد با ستون: {$col}\n";
            }
        } catch (\Throwable $e) {
            echo "❌ خطا در {$col}: ".$e->getMessage()."\n";
        }
    }
    
    echo "\n❌ Invoice با هیچ یک از ستون‌ها یافت نشد!\n";
    
} catch (\Throwable $e) {
    echo "❌ خطا: ".$e->getMessage()."\n";
    exit(1);
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
