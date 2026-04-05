<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'xmplus_inv_id')) {
                $table->string('xmplus_inv_id', 100)->nullable()->after('panel_client_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'xmplus_inv_id')) {
                $table->dropColumn('xmplus_inv_id');
            }
        });
    }
};
