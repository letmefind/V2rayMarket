<?php

/**
 * نقطهٔ ورود پیش‌فرض وقتی در تنظیمات درگاه XMPlus فیلد handler_class خالی است.
 */

namespace App\Services\Gateway;

final class ShopPrepaidConfirmHandler
{
    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $config
     */
    public static function settle(array $order, array $config): void
    {
        ShopPrepaidConfirmKernel::settle($order, $config);
    }
}
