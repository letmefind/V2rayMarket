<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 5)->unique();
            $table->string('title')->nullable();
            $table->longText('payload');
            $table->timestamp('last_shared_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_shares');
    }
};
