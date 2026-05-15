<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * تشخیص ستون بدون کشِ گمراه‌کنندهٔ Schema در میانهٔ migrate (information_schema روی MySQL).
 */
final class SchemaUtil
{
    public static function tableHasColumn(string $table, string $column): bool
    {
        try {
            $driver = DB::connection()->getDriverName();
            if ($driver === 'sqlite') {
                return Schema::hasColumn($table, $column);
            }

            $row = DB::selectOne(
                'SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column]
            );

            return $row !== null && (int) $row->n > 0;
        } catch (\Throwable) {
            return Schema::hasColumn($table, $column);
        }
    }
}
