<?php

/**
 * منطق واقعی «بستن فاکتور مثل Confirm ادمین» باید با نسخهٔ XMPlus شما یکی باشد.
 *
 * این فایل چند نام متد رایج را روی مدل Invoice امتحان می‌کند؛ اگر هیچ‌کدام نبود،
 * در App/Application/Models/Invoice.php (یا مسیر معادل) بگردید و همان متدی را که
 * ادمین هنگام تأیید فاکتور صدا می‌زند اینجا فراخوانی کنید.
 */

namespace App\Services\Gateway;

use RuntimeException;

final class ShopPrepaidConfirmKernel
{
    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $config
     */
    public static function settle(array $order, array $config): void
    {
        $invId = trim((string) ($order['order_id'] ?? $order['invid'] ?? $order['orderid'] ?? ''));
        if ($invId === '') {
            throw new RuntimeException('ShopPrepaidConfirmKernel: missing invoice id in $order (expected order_id).');
        }

        $invoiceFqcn = 'App\\Application\\Models\\Invoice';
        if (! class_exists($invoiceFqcn)) {
            throw new RuntimeException(
                "ShopPrepaidConfirmKernel: class {$invoiceFqcn} not found. Edit this file and set \$invoiceFqcn to your panel's Invoice model."
            );
        }

        $invoice = $invoiceFqcn::where('inv_id', $invId)->first();
        if ($invoice === null) {
            throw new RuntimeException("ShopPrepaidConfirmKernel: no invoice for inv_id={$invId}.");
        }

        if ((int) ($invoice->status ?? 0) === 1) {
            return;
        }

        $instanceMethods = [
            'confirmByReseller',
            'confirmByAdmin',
            'setPaid',
            'markPaid',
            'complete',
            'finalizePayment',
            'payWithResellerBalance',
            'paid',
            'confirm',
        ];

        foreach ($instanceMethods as $method) {
            if (! method_exists($invoice, $method)) {
                continue;
            }
            $invoice->$method();
            $fresh = $invoiceFqcn::where('inv_id', $invId)->first();
            if ($fresh !== null && (int) ($fresh->status ?? 0) === 1) {
                return;
            }
        }

        throw new RuntimeException(
            'ShopPrepaidConfirmKernel: invoice '.$invId.' is still not paid after trying common methods. '
            .'Inspect your XMPlus Invoice model and admin «confirm payment» action, then add an explicit call in ShopPrepaidConfirmKernel::settle().'
        );
    }
}
