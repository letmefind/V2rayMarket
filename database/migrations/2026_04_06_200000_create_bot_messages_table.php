<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_messages', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique()->comment('کلید یکتا برای شناسایی پیام');
            $table->string('category')->default('general')->comment('دسته‌بندی: buttons, messages, errors, etc');
            $table->string('title')->comment('عنوان فارسی برای مدیریت');
            $table->text('content')->comment('متن پیام یا دکمه');
            $table->text('description')->nullable()->comment('توضیحات و متغیرهای قابل استفاده');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_messages');
    }
};
