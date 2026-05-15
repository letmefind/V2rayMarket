<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('service_label', 64)->nullable()->after('panel_username');
        });

        // سفارش‌های قدیمی: اگر panel_username هنوز نام انتخابی است (بدون @)، در service_label کپی شود
        DB::table('orders')
            ->whereNull('service_label')
            ->whereNotNull('panel_username')
            ->where('panel_username', 'not like', '%@%')
            ->update([
                'service_label' => DB::raw('panel_username'),
            ]);
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('service_label');
        });
    }
};
