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
 * pay() برای UI پورتال: ret=1 همراه code=100 یعنی پرداخت فوری (ret=2 در Client API گاهی به code=208 خطا تبدیل می‌شد).
 * برای Client API فروشگاه: code=100 و بدون qrcode / data رشته‌ای پر — تا polling VPNMarket درست کار کند.
 */

namespace App\Services\Gateway;

use App\Utility\Localization;
use ReflectionClass;
use ReflectionMethod;
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
					var paidOk = (data.code == 100 || data.status == 'success' || data.status == 'Success');
					if (data.ret == 2 || (data.ret == 1 && paidOk)) {
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
        $msg = Localization::get('PaymentSuccess') ?: 'Payment successful.';
        $numericId = $this->extractInvoiceNumericId($order);

        /*
         * Client API پنل XMPlus با ret=2 گاهی پاسخ را به status=error / code=208 تبدیل می‌کند.
         * ret=1 معمولاً «پاسخ درگاه» است؛ با code=100 و data خالی، هم API و هم فروشگاه VPNMarket راضی می‌مانند.
         */
        $out = [
            'ret' => 1,
            'msg' => $msg,
            'status' => 'success',
            'code' => 100,
            'gateway' => 'ShopPrepaidConfirm',
            'orderid' => $orderid,
            'data' => '',
        ];
        if ($numericId !== null) {
            $out['id'] = $numericId;
        }

        return $out;
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

    /**
     * شناسهٔ عددی ردیف فاکتور در DB (در صورت وجود در $order).
     *
     * @param  array<string, mixed>|object  $order
     */
    private function extractInvoiceNumericId($order): ?int
    {
        if (is_array($order) && isset($order['id']) && is_numeric($order['id'])) {
            $n = (int) $order['id'];

            return $n > 0 ? $n : null;
        }
        if (is_object($order) && isset($order->id) && is_numeric($order->id)) {
            $n = (int) $order->id;

            return $n > 0 ? $n : null;
        }

        return null;
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

        if (self::invoiceRowLooksPaid($invoice)) {
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
            'pay',
            'checkout',
            'executePay',
            'payInvoice',
            'activateService',
            'provisionService',
        ];

        foreach ($instanceMethods as $method) {
            if (! method_exists($invoice, $method)) {
                continue;
            }
            try {
                $invoice->$method();
            } catch (Throwable $e) {
                continue;
            }
            $invoice = $invoiceFqcn::where('inv_id', $invId)->first();
            if ($invoice !== null && self::invoiceRowLooksPaid($invoice)) {
                return;
            }
        }

        self::tryReflectionParameterlessPayMethods($invoiceFqcn, $invId);

        $invoice = $invoiceFqcn::where('inv_id', $invId)->first();
        if ($invoice !== null && self::invoiceRowLooksPaid($invoice)) {
            return;
        }

        self::tryForcePaidAttributes($invoiceFqcn, $invoice, $invId, $order);

        $invoice = $invoiceFqcn::where('inv_id', $invId)->first();
        if ($invoice !== null && self::invoiceRowLooksPaid($invoice)) {
            return;
        }

        throw new RuntimeException(
            'ShopPrepaidConfirmKernel: invoice '.$invId.' could not be marked paid automatically. '
            .'On the XMPlus server open App/Application/Models/Invoice.php and the admin route/controller that confirms payment; '
            .'add one explicit call to that logic at the end of ShopPrepaidConfirmKernel::settle() in ShopPrepaidConfirm.php.'
        );
    }

    /**
     * @param  object  $invoice
     */
    private static function invoiceRowLooksPaid($invoice): bool
    {
        $st = $invoice->status ?? null;
        if ($st === 1 || $st === '1' || $st === true) {
            return true;
        }
        if (is_string($st) && strcasecmp($st, 'paid') === 0) {
            return true;
        }

        return false;
    }

    /**
     * @param  class-string  $invoiceFqcn
     */
    private static function tryReflectionParameterlessPayMethods(string $invoiceFqcn, string $invId): void
    {
        try {
            $ref = new ReflectionClass($invoiceFqcn);
        } catch (Throwable $e) {
            return;
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            if ($rm->isStatic() || $rm->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if ($rm->getDeclaringClass()->getName() !== $invoiceFqcn) {
                continue;
            }
            $name = $rm->getName();
            if (strpos($name, '__') === 0) {
                continue;
            }
            if (preg_match('/^(get|set|is|has|to|new|create|delete|find|all|where|query)/i', $name) === 1) {
                continue;
            }
            if (preg_match('/confirm|complete|paid|pay|approve|finalize|settle|activate|provision|checkout|execute/i', $name) !== 1) {
                continue;
            }

            $working = $invoiceFqcn::where('inv_id', $invId)->first();
            if ($working === null) {
                return;
            }

            try {
                $working->$name();
            } catch (Throwable $e) {
                continue;
            }

            $fresh = $invoiceFqcn::where('inv_id', $invId)->first();
            if ($fresh !== null && self::invoiceRowLooksPaid($fresh)) {
                return;
            }
        }
    }

    /**
     * آخرین تلاش: مثل به‌روزرسانی ردیف در ادمین (ممکن است در برخی نصب‌ها Observer سرویس بسازد).
     *
     * @param  class-string  $invoiceFqcn
     * @param  array<string, mixed>  $order
     */
    private static function tryForcePaidAttributes(string $invoiceFqcn, object $invoice, string $invId, array $order): void
    {
        $attrs = ['status' => 1];
        $now = date('Y-m-d H:i:s');

        $dateCols = ['paid_date', 'paid_at', 'pay_time', 'paid_time'];
        foreach ($dateCols as $col) {
            if (self::modelHasAttributeKey($invoice, $col)) {
                $attrs[$col] = $now;
                break;
            }
        }

        $amount = $order['total_amount'] ?? $order['total'] ?? null;
        if ($amount !== null && $amount !== '') {
            $amountCols = ['paid_amount', 'amount_paid', 'pay_amount'];
            foreach ($amountCols as $col) {
                if (self::modelHasAttributeKey($invoice, $col)) {
                    $attrs[$col] = $amount;
                    break;
                }
            }
        }

        try {
            if (method_exists($invoice, 'forceFill')) {
                $invoice->forceFill($attrs)->save();
            } else {
                foreach ($attrs as $k => $v) {
                    $invoice->{$k} = $v;
                }
                if (method_exists($invoice, 'save')) {
                    $invoice->save();
                }
            }
        } catch (Throwable $e) {
            // try query builder update (نادیده گرفتن fillable)
        }

        try {
            if (method_exists($invoiceFqcn, 'where')) {
                $invoiceFqcn::where('inv_id', $invId)->limit(1)->update($attrs);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    /**
     * @param  object  $invoice
     */
    private static function modelHasAttributeKey(object $invoice, string $key): bool
    {
        if (method_exists($invoice, 'getAttributes')) {
            /** @var array<string, mixed> $a */
            $a = $invoice->getAttributes();

            return array_key_exists($key, $a);
        }

        return property_exists($invoice, $key);
    }
}
