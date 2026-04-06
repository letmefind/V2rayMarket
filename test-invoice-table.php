<?php

/**
 * اسکریپت تست ساختار جدول invoice در XMPlus
 * 
 * برای اجرا:
 * php test-invoice-table.php
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\XmplusInvoiceDatabaseSyncService;
use App\Models\Setting;

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🔍 بررسی ساختار جدول invoice در XMPlus\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$settings = Setting::all()->pluck('value', 'key');

// 1. بررسی اتصال
echo "1️⃣ تست اتصال به دیتابیس...\n";
$testResult = XmplusInvoiceDatabaseSyncService::testConnection($settings);

if (!$testResult['ok']) {
    echo "❌ خطا: ".$testResult['message']."\n";
    exit(1);
}

echo "✅ ".$testResult['message']."\n\n";

// 2. بررسی ساختار جدول
echo "2️⃣ بررسی ساختار جدول invoice...\n";
$inspectResult = XmplusInvoiceDatabaseSyncService::inspectInvoiceTable($settings);

if (!$inspectResult['ok']) {
    echo "❌ خطا: ".$inspectResult['message']."\n";
    exit(1);
}

echo "✅ ".$inspectResult['message']."\n\n";

// 3. نمایش ستون‌ها
echo "3️⃣ ستون‌های موجود در جدول:\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

$serviceColumns = [];
foreach ($inspectResult['columns'] as $col) {
    $isServiceCol = stripos($col, 'service') !== false || $col === 'sid';
    $marker = $isServiceCol ? '🔵' : '  ';
    echo "{$marker} {$col}\n";
    
    if ($isServiceCol) {
        $serviceColumns[] = $col;
    }
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// 4. نتیجه‌گیری
if (empty($serviceColumns)) {
    echo "❌ هیچ ستونی برای ذخیره service_id یافت نشد!\n";
    echo "   جدول invoice باید یکی از این ستون‌ها را داشته باشد:\n";
    echo "   - serviceid\n";
    echo "   - service_id\n";
    echo "   - sid\n\n";
    echo "💡 راه‌حل: در دیتابیس XMPlus این دستور را اجرا کنید:\n";
    echo "   ALTER TABLE `invoice` ADD COLUMN `serviceid` VARCHAR(50) DEFAULT NULL AFTER `userid`;\n\n";
} else {
    echo "✅ ستون‌های service پیدا شده:\n";
    foreach ($serviceColumns as $col) {
        echo "   🔵 {$col}\n";
    }
    echo "\n";
    echo "✅ کد به‌روزرسانی شده و این ستون‌ها را خودکار تشخیص می‌دهد.\n";
}

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ تست کامل شد!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
