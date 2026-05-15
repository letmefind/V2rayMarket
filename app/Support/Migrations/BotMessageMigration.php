<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Upsert/delete در migration بدون Eloquent تا قبل از وجود ستون instance_id خطا ندهد.
 */
final class BotMessageMigration
{
    public static function instanceIdForMigration(): string
    {
        $id = config('app.instance_id');

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return (string) env('APP_INSTANCE_ID', 'default');
    }

    /** @param  array<string, mixed>  $attributes  شامل key، بدون instance_id تا قبل از migrate ستون */
    public static function upsert(array $attributes): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        $key = $attributes['key'];
        unset($attributes['key']);

        $now = now();
        $attributes['updated_at'] = $now;

        $query = DB::table('bot_messages')->where('key', $key);
        if (Schema::hasColumn('bot_messages', 'instance_id')) {
            $instanceId = self::instanceIdForMigration();
            $query->where('instance_id', $instanceId);
            $attributes['instance_id'] = $instanceId;
        }

        $existing = $query->first();
        if ($existing !== null) {
            DB::table('bot_messages')->where('id', $existing->id)->update($attributes);

            return;
        }

        $attributes['key'] = $key;
        $attributes['created_at'] = $now;
        DB::table('bot_messages')->insert($attributes);
    }

    /** @param  array<string, mixed>  $attributes */
    public static function updateByKey(string $key, array $attributes): void
    {
        if (! Schema::hasTable('bot_messages')) {
            return;
        }

        $attributes['updated_at'] = now();
        $query = DB::table('bot_messages')->where('key', $key);
        if (Schema::hasColumn('bot_messages', 'instance_id')) {
            $query->where('instance_id', self::instanceIdForMigration());
        }
        $query->update($attributes);
    }

    /** @param  list<string>  $keys */
    public static function deleteWhereKeys(array $keys): void
    {
        if (! Schema::hasTable('bot_messages') || $keys === []) {
            return;
        }

        $query = DB::table('bot_messages')->whereIn('key', $keys);
        if (Schema::hasColumn('bot_messages', 'instance_id')) {
            $query->where('instance_id', self::instanceIdForMigration());
        }
        $query->delete();
    }

    public static function deleteWhereKey(string $key): void
    {
        self::deleteWhereKeys([$key]);
    }
}
