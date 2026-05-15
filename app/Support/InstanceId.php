<?php

namespace App\Support;

final class InstanceId
{
    public static function current(): string
    {
        $id = config('app.instance_id');

        return is_string($id) && $id !== '' ? $id : 'default';
    }

    public static function isSharePickupOnly(): bool
    {
        return (bool) config('app.share_pickup_only');
    }
}
