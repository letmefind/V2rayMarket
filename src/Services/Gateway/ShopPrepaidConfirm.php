<?php

/**
 * درگاه «پرداخت از پیش توسط فروشگاه» — الگوی همان کلاس‌های XMPlus (مثل Card2Card.php).
 *
 * نصب: کپی در src/Services/Gateway/ روی سرور پنل، سپس از ادمین درگاه را اضافه کنید و
 * در VPNMarket شناسهٔ عددی را از POST /api/client/gateways قرار دهید.
 *
 * Handler و Kernel در همین فایل تعریف شده‌اند تا XMPlus (که اغلب فقط همین فایل را لود می‌کند)
 * نیاز به فایل یا autoload اضافه نداشته باشد. در صورت نیاز منطق را در ShopPrepaidConfirmKernel::settle() تکمیل کنید.
 *
 * pay() برای UI پورتال: ret=2 یعنی پرداخت فوری و ریدایرکت به invoice/view (مثل Card2Card).
 * برای Client API فروشگاه: code=100 و بدون qrcode / data رشته‌ای پر — تا polling VPNMarket درست کار کند.
 */

namespace App\Services\Gateway;

use App\Utility\Localization;
use RuntimeException;
use Throwable;

final class ShopPrepaidConfirm
{
    /** @var array<string, mixed> */
    protected $config;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct($config)
    {
        $this->config = is_array($config) ? $config : [];
    }

    /**
     * @return array<string, string>
     */
    public function form()
    {
        $gatewayName = htmlspecialchars((string) ($this->config['name'] ?? 'ShopPrepaidConfirm'), ENT_QUOTES, 'UTF-8');
        $handlerClass = htmlspecialchars((string) ($this->config['handler_class'] ?? ''), ENT_QUOTES, 'UTF-8');
        $handlerMethod = htmlspecialchars((string) ($this->config['handler_method'] ?? 'settle'), ENT_QUOTES, 'UTF-8');

        return [
            'name' => '<div class="row mb-3">
								<label for="name" class="col-sm-3 col-form-label form-label">'.Localization::get('Gateway').'</label>
								<div class="col-sm-8">
									<input type="text" class="form-control shadow-lg" id="name" name="config[name]" value="'.$gatewayName.'">
								</div>
							</div>',

            'handler_class' => '<div class="row mb-3">
								<label for="handler_class" class="col-sm-3 col-form-label form-label">Handler class (FQCN)</label>
								<div class="col-sm-8">
									<input type="text" class="form-control shadow-lg" id="handler_class" name="config[handler_class]" value="'.$handlerClass.'" placeholder="(optional) App\\Services\\Gateway\\ShopPrepaidConfirmHandler">
									<small class="text-muted">اختیاری: اگر خالی بماند، پیش‌فرض ShopPrepaidConfirmHandler → ShopPrepaidConfirmKernel است. برای کلاس سفارشی، FQCN را بگذارید.</small>
								</div>
							</div>',

            'handler_method' => '<div class="row mb-3">
								<label for="handler_method" class="col-sm-3 col-form-label form-label">Handler method</label>
								<div class="col-sm-8">
									<input type="text" class="form-control shadow-lg" id="handler_method" name="config[handler_method]" value="'.$handlerMethod.'" placeholder="settle">
									<small class="text-muted">امضا: public static function settle(array $order, array $config): void</small>
								</div>
							</div>',
        ];
    }

    public function button()
    {
        return '<button class="btn btn-dark checkout mb-2" style="width:100%;">'.Localization::get('PayNow').'</button>';
    }

    public function modal()
    {
        return '';
    }

    public function script()
    {
        $html = <<<'HTML'
		<script>
		$('.checkout').click(function(e) {
			var me = $(this);
			e.preventDefault();
			if ( me.data('requestRunning') ) {
				return;
			}
			me.data('requestRunning', true);
			layer.load(2);
			if($("#isreseller").val() == 1){
				var url = "/reseller/invoice/checkout";
			}else{
				var url = "/portal/invoice/checkout";
			}
			$.ajax({
				type: "POST",
				url: url,
				dataType: "json",
				data: {
					tk_name:$("#csrf_name").val(),
					tk_value:$("#csrf_value").val(),
					invoiceid : $("#invoiceid").val(),
					gatewayid: $("#gatewayid").val()
				},
				success: (data) => {
					layer.closeAll('loading');
					if (data.ret == 2) {
						layer.msg(data.msg, {
							time: 5000,
							offset:  '100px'
						});
						window.setTimeout('location.href="invoice/view"', 1500);
					} else if (data.ret == 1) {
						layer.msg(data.msg || 'Unexpected ret=1 for ShopPrepaidConfirm', {
							time: 5000,
							offset:  '100px'
						});
					} else {
						layer.msg(data.msg || 'Error', {
							time: 5000,
							offset:  '100px'
						});
					}
				},
				error: (jqXHR) => {
					layer.closeAll('loading');
					layer.msg(jqXHR.responseText, {
						time: 8000,
						offset:  '100px'
					});
				},
				complete: () => {
					layer.closeAll('loading');
					me.data('requestRunning', false);
				}
			});
		});
		</script>
		HTML;

        return $html;
    }

    /**
     * @param  array<string, mixed>|object  $order
     * @return array<string, mixed>
     */
    public function pay($order)
    {
        try {
            $this->invokeSettleHandler($order);
        } catch (Throwable $e) {
            return [
                'ret' => 0,
                'msg' => $e->getMessage(),
            ];
        }

        $orderid = $this->extractOrderPublicId($order);

        return [
            'ret' => 2,
            'msg' => Localization::get('PaymentSuccess') ?: 'Payment successful.',
            'status' => 'success',
            'code' => 100,
            'gateway' => 'ShopPrepaidConfirm',
            'orderid' => $orderid,
        ];
    }

    /**
     * @param  mixed  $params
     * @return false|array<string, mixed>
     */
    public function notify($params)
    {
        return false;
    }

    /**
     * @param  array<string, mixed>|object  $order
     */
    private function invokeSettleHandler($order): void
    {
        $class = trim((string) ($this->config['handler_class'] ?? ''));
        if ($class === '') {
            $class = ShopPrepaidConfirmHandler::class;
        }
        if (! class_exists($class)) {
            throw new RuntimeException("ShopPrepaidConfirm: class not found: {$class}");
        }

        $method = trim((string) ($this->config['handler_method'] ?? 'settle'));
        if ($method === '') {
            $method = 'settle';
        }

        if (! is_callable([$class, $method])) {
            throw new RuntimeException(
                "ShopPrepaidConfirm: callable not found: {$class}::{$method}()"
            );
        }

        $orderArr = $this->normalizeOrderArray($order);

        try {
            $class::$method($orderArr, $this->config);
        } catch (Throwable $e) {
            throw new RuntimeException(
                'ShopPrepaidConfirm: handler error — '.$e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * @param  array<string, mixed>|object  $order
     * @return array<string, mixed>
     */
    private function normalizeOrderArray($order): array
    {
        if (is_array($order)) {
            return $order;
        }
        if (is_object($order) && method_exists($order, 'toArray')) {
            /** @var mixed $ta */
            $ta = $order->toArray();

            return is_array($ta) ? $ta : [];
        }

        return [];
    }

    /**
     * در Card2Card از order['order_id'] برای inv_id استفاده می‌شود.
     *
     * @param  array<string, mixed>|object  $order
     */
    private function extractOrderPublicId($order): string
    {
        if (is_array($order)) {
            foreach (['order_id', 'invioce_id', 'invoice_id', 'invid', 'orderid', 'trade_no', 'id'] as $key) {
                if (isset($order[$key]) && (is_string($order[$key]) || is_numeric($order[$key]))) {
                    return trim((string) $order[$key]);
                }
            }
        } elseif (is_object($order)) {
            foreach (['order_id', 'invioce_id', 'invoice_id', 'invid', 'orderid', 'trade_no', 'id'] as $key) {
                if (isset($order->{$key}) && (is_string($order->{$key}) || is_numeric($order->{$key}))) {
                    return trim((string) $order->{$key});
                }
            }
            if (method_exists($order, 'getInvioceId')) {
                $v = $order->getInvioceId();
                if (is_string($v) || is_numeric($v)) {
                    return trim((string) $v);
                }
            }
            if (method_exists($order, 'getInvoiceId')) {
                $v = $order->getInvoiceId();
                if (is_string($v) || is_numeric($v)) {
                    return trim((string) $v);
                }
            }
        }

        return '';
    }
}

/**
 * پیش‌فرض وقتی handler_class در تنظیمات درگاه خالی است (همان فایل — بدون require جدا).
 */
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

/**
 * تلاش برای بستن فاکتور مثل Confirm ادمین؛ در صورت نیاز متد واقعی پنل را اینجا صدا بزنید.
 */
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
                "ShopPrepaidConfirmKernel: class {$invoiceFqcn} not found. Edit ShopPrepaidConfirm.php and set \$invoiceFqcn to your panel's Invoice model."
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
            .'Inspect your XMPlus Invoice model and admin «confirm payment» action, then add an explicit call in ShopPrepaidConfirmKernel::settle() in ShopPrepaidConfirm.php.'
        );
    }
}
