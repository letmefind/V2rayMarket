<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'xmplus_client_email')) {
                $table->string('xmplus_client_email', 191)->nullable()->after('referral_code');
            }
            if (! Schema::hasColumn('users', 'xmplus_client_password')) {
                $table->text('xmplus_client_password')->nullable()->after('xmplus_client_email');
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (! Schema::hasColumn('plans', 'xmplus_package_id')) {
                $table->unsignedInteger('xmplus_package_id')->nullable()->after('duration_days');
            }
            if (! Schema::hasColumn('plans', 'xmplus_billing')) {
                $table->string('xmplus_billing', 32)->nullable()->after('xmplus_package_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'xmplus_client_password')) {
                $table->dropColumn('xmplus_client_password');
            }
            if (Schema::hasColumn('users', 'xmplus_client_email')) {
                $table->dropColumn('xmplus_client_email');
            }
        });

        Schema::table('plans', function (Blueprint $table) {
            if (Schema::hasColumn('plans', 'xmplus_billing')) {
                $table->dropColumn('xmplus_billing');
            }
            if (Schema::hasColumn('plans', 'xmplus_package_id')) {
                $table->dropColumn('xmplus_package_id');
            }
        });
    }
};
