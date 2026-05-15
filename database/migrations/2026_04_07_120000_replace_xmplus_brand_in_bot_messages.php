<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        DB::table('bot_messages')->orderBy('id')->chunk(100, function ($rows): void {
            foreach ($rows as $m) {
                $title = str_ireplace('xmplus', 'BypassNET', (string) $m->title);
                $content = str_ireplace('xmplus', 'BypassNET', (string) $m->content);
                $desc = $m->description !== null
                    ? str_ireplace('xmplus', 'BypassNET', (string) $m->description)
                    : null;

                if ($title !== $m->title || $content !== $m->content || $desc !== $m->description) {
                    DB::table('bot_messages')->where('id', $m->id)->update([
                        'title' => $title,
                        'content' => $content,
                        'description' => $desc,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Brand text change is not safely reversible.
    }
};
