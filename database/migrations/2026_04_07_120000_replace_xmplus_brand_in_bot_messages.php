<?php

use App\Models\BotMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        BotMessage::query()->chunkById(100, function ($rows): void {
            foreach ($rows as $m) {
                $title = str_ireplace('xmplus', 'BypassNET', (string) $m->title);
                $content = str_ireplace('xmplus', 'BypassNET', (string) $m->content);
                $desc = $m->description !== null
                    ? str_ireplace('xmplus', 'BypassNET', (string) $m->description)
                    : null;

                if ($title !== $m->title || $content !== $m->content || $desc !== $m->description) {
                    $m->forceFill([
                        'title' => $title,
                        'content' => $content,
                        'description' => $desc,
                    ])->saveQuietly();
                }
            }
        });

        BotMessage::clearCache();
    }

    public function down(): void
    {
        // Brand text change is not safely reversible.
    }
};
