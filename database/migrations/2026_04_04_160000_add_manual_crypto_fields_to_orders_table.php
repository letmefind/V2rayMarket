<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('crypto_network', 32)->nullable();
            $table->string('crypto_tx_hash', 128)->nullable();
            $table->decimal('crypto_amount_expected', 24, 8)->nullable();
            $table->string('crypto_payment_proof')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['crypto_network', 'crypto_tx_hash', 'crypto_amount_expected', 'crypto_payment_proof']);
        });
    }
};
