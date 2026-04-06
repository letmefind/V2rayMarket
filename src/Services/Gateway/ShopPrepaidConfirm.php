<?php

/**
 * درگاه XMPlus: «پرداخت از پیش توسط فروشگاه» (ShopPrepaidConfirm).
 *
 * — کپی در `src/Services/Gateway/` روی سرور پنل (مثل Card2Card.php هم‌مسیر).
 * — Handler و Kernel در همین فایل‌اند (XMPlus اغلب فقط همین فایل را لود می‌کند).
 *
 * تفاوت با Card2Card (نمونهٔ symmetricnet):
 *   Card2Card در pay() فاکتور را Paid نمی‌کند؛ فقط QR/آدرس می‌دهد و فاکتور با status=0 می‌ماند تا
 *   مسیرهایی مثل /portal/invoice/query یا notify تکمیل شود. این درگاه باید همان «تکمیل پرداخت»
 *   را یک‌جا شبیه‌سازی کند؛ بنابراین علاوه بر به‌روز کردن ردیف، متد pay(شناسهٔ درگاه) و
 *   App\Utility\Helpers (در صورت وجود) هم امتحان می‌شود. اگر پنل شما تابع اختصاصی دارد،
 *   در hookPanelFulfill() همان یک خط را بگذارید. روی سرور: در Gatewayها معمولاً فقط JS به /portal/invoice/query
 *   اشاره می‌کند؛ کنترلر PHP را با جستجوی محدود به *.php از ریشهٔ public_html پیدا کنید:
 *   grep -R "invoice/query" --include="*.php" .
 *   symmetricnet (نمونه): app/routes/user.php → POST …/invoice/query →
 *   \App\Application\Controller\User\InvoiceController::check
 *   app/routes/reseller.php → \App\Application\Controller\Reseller\InvoiceController::check
 *   متد check معمولاً به Request/Response وابسته است؛ همان بلوکی را که بعد از تأیید پرداخت سرویس می‌سازد
 *   در یک کلاس استاتیک کوچک بگذارید و در تنظیمات درگاه after_paid_class / after_paid_method را پر کنید،
 *   یا در hookPanelFulfill() صدا بزنید.
 *
 *   نکتهٔ تکنیکی: اگر ابتدا فقط status/paid_date بدون pay($gatewayId) ست شود، fulfillAfterPaid
 *   دوباره همان pay را با «حتی اگر از قبل Paid» صدا می‌زند تا serviceid پر شود؛ در غیر این صورت API با
 *   services: [] می‌ماند.
 *
 * قرارداد pay() برای Client API / VPNMarket:
 *   ret=1, code=100, status=success, gateway=ShopPrepaidConfirm, data=''
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
    protected array $config;

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
    public function form(): array
    {
        $gatewayName = htmlspecialchars((string) ($this->config['name'] ?? 'ShopPrepaidConfirm'), ENT_QUOTES, 'UTF-8');
        $handlerClass = htmlspecialchars((string) ($this->config['handler_class'] ?? ''), ENT_QUOTES, 'UTF-8');
        $handlerMethod = htmlspecialchars((string) ($this->config['handler_method'] ?? 'settle'), ENT_QUOTES, 'UTF-8');
        $afterPaidClass = htmlspecialchars((string) ($this->config['after_paid_class'] ?? ''), ENT_QUOTES, 'UTF-8');
        $afterPaidMethod = htmlspecialchars((string) ($this->config['after_paid_method'] ?? 'handle'), ENT_QUOTES, 'UTF-8');

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
									<small class="text-muted">خالی = پیش‌فرض ShopPrepaidConfirmHandler. برای کلاس سفارشی FQCN بگذارید.</small>
								</div>
							</div>',
            'handler_method' => '<div class="row mb-3">
								<label for="handler_method" class="col-sm-3 col-form-label form-label">Handler method</label>
								<div class="col-sm-8">
									<input type="text" class="form-control shadow-lg" id="handler_method" name="config[handler_method]" value="'.$handlerMethod.'" placeholder="settle">
									<small class="text-muted">امضا: public static function settle(array $order, array $config): void</small>
								</div>
							</div>',
            'after_paid_class' => '<div class="row mb-3">
								<label for="after_paid_class" class="col-sm-3 col-form-label form-label">After-paid class (FQCN)</label>
								<div class="col-sm-8">
									<input type="text" class="form-control shadow-lg" id="after_paid_class" name="config[after_paid_class]" value="'.$afterPaidClass.'" placeholder="مثال: App\\Services\\ShopPrepaidAfterPaid">
									<small class="text-muted">اختیاری. منطق همان <code>User\\InvoiceController::check</code> را اینجا استاتیک کنید (Slim را مستقیم صدا نزنید).</small>
								</div>
							</div>',
            'after_paid_method' => '<div class="row mb-3">
								<label for="after_paid_method" class="col-sm-3 col-form-label form-label">After-paid method</label>
								<div class="col-sm-8">
									<input type="text" class="form-control shadow-lg" id="after_paid_method" name="config[after_paid_method]" value="'.$afterPaidMethod.'" placeholder="handle">
									<small class="text-muted">متد استاتیک؛ ۰ تا ۳ آرگومان اجباری: () | (\$invoice) | (\$invoice,\$invId) | (\$invoice,\$invId,\$gatewayId)</small>
								</div>
							</div>',
        ];
    }

    public function button(): string
    {
        return '<button class="btn btn-dark checkout mb-2" style="width:100%;">'.Localization::get('PayNow').'</button>';
    }

    public function modal(): string
    {
        return '';
    }

    public function script(): string
    {
        return <<<'HTML'
		<script>
		$('.checkout').click(function(e) {
			var me = $(this);
			e.preventDefault();
			if ( me.data('requestRunning') ) {
				return;
			}
			me.data('requestRunning', true);
			layer.load(2);
			var url = $("#isreseller").val() == 1 ? "/reseller/invoice/checkout" : "/portal/invoice/checkout";
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
						layer.msg(data.msg, { time: 5000, offset:  '100px' });
						window.setTimeout('location.href="invoice/view"', 1500);
					} else if (data.ret == 1) {
						layer.msg(data.msg || 'Unexpected ret=1 for ShopPrepaidConfirm', { time: 5000, offset:  '100px' });
					} else {
						layer.msg(data.msg || 'Error', { time: 5000, offset:  '100px' });
					}
				},
				error: (jqXHR) => {
					layer.closeAll('loading');
					layer.msg(jqXHR.responseText, { time: 8000, offset:  '100px' });
				},
				complete: () => {
					layer.closeAll('loading');
					me.data('requestRunning', false);
				}
			});
		});
		</script>
		HTML;
    }

    /**
     * @param  array<string, mixed>|object  $order
     * @return array<string, mixed>
     */
    public function pay($order): array
    {
        try {
            $this->invokeSettleHandler($order);
        } catch (Throwable $e) {
            return ['ret' => 0, 'msg' => $e->getMessage()];
        }

        $msg = Localization::get('PaymentSuccess') ?: 'Payment successful.';
        $out = [
            'ret' => 1,
            'msg' => $msg,
            'status' => 'success',
            'code' => 100,
            'gateway' => 'ShopPrepaidConfirm',
            'orderid' => ShopPrepaidConfirmOrder::publicId($order),
            'data' => '',
        ];

        $numId = ShopPrepaidConfirmOrder::numericInvoiceId($order);
        if ($numId !== null) {
            $out['id'] = $numId;
        }

        $amountStr = ShopPrepaidConfirmOrder::amountString($order);
        if ($amountStr !== null) {
            $out['amount'] = $amountStr;
            $out['total'] = $amountStr;
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
            throw new RuntimeException("ShopPrepaidConfirm: callable not found: {$class}::{$method}()");
        }

        $orderArr = ShopPrepaidConfirmOrder::toArray($order);

        try {
            $class::$method($orderArr, $this->config);
        } catch (Throwable $e) {
            throw new RuntimeException('ShopPrepaidConfirm: handler error — '.$e->getMessage(), (int) $e->getCode(), $e);
        }
    }
}

/**
 * استخراج فیلدهای رایج از آرایه/مدل سفارش فاکتور.
 */
final class ShopPrepaidConfirmOrder
{
    /**
     * @param  array<string, mixed>|object  $order
     * @return array<string, mixed>
     */
    public static function toArray($order): array
    {
        if (is_array($order)) {
            return $order;
        }
        if (is_object($order) && method_exists($order, 'toArray')) {
            $ta = $order->toArray();

            return is_array($ta) ? $ta : [];
        }

        return [];
    }

    /**
     * شناسهٔ عمومی فاکتور (inv_id) برای پاسخ درگاه.
     *
     * @param  array<string, mixed>|object  $order
     */
    public static function publicId($order): string
    {
        // inv_id قبل از id تا اگر هر دو در آرایه بود، کلید عمومی رشته‌ای انتخاب شود نه PK عددی جدول.
        $keys = ['order_id', 'inv_id', 'invioce_id', 'invoice_id', 'invid', 'orderid', 'trade_no', 'id'];

        if (is_array($order)) {
            foreach ($keys as $key) {
                if (isset($order[$key]) && (is_string($order[$key]) || is_numeric($order[$key]))) {
                    return trim((string) $order[$key]);
                }
            }
        } elseif (is_object($order)) {
            foreach ($keys as $key) {
                if (isset($order->{$key}) && (is_string($order->{$key}) || is_numeric($order->{$key}))) {
                    return trim((string) $order->{$key});
                }
            }
            foreach (['getInvioceId', 'getInvoiceId'] as $getter) {
                if (method_exists($order, $getter)) {
                    $v = $order->{$getter}();
                    if (is_string($v) || is_numeric($v)) {
                        return trim((string) $v);
                    }
                }
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>|object  $order
     */
    public static function numericInvoiceId($order): ?int
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

    /**
     * @param  array<string, mixed>|object  $order
     */
    public static function amountString($order): ?string
    {
        $arr = self::toArray($order);
        foreach (['total_amount', 'total', 'amount', 'price'] as $k) {
            if (! isset($arr[$k]) || $arr[$k] === '' || $arr[$k] === null) {
                continue;
            }
            if (is_numeric($arr[$k]) || is_string($arr[$k])) {
                return trim((string) $arr[$k]);
            }
        }

        return null;
    }

    /**
     * شناسهٔ عمومی فاکتور از آرایهٔ سفارش (همان publicId؛ شامل invioce_id مثل خروجی Client API).
     *
     * @param  array<string, mixed>  $order
     */
    public static function invoicePublicKey(array $order): string
    {
        return self::publicId($order);
    }
}

/**
 * پیش‌فرض handler وقتی `handler_class` خالی است.
 */
final class ShopPrepaidConfirmHandler
{
    /**
     * @param  array<string, mixed>  $order  معمولاً شامل order_id (= inv_id) مثل Card2Card::pay()
     * @param  array<string, mixed>  $config  شامل id درگاه از پنل (در صورت پاس دادن توسط هستهٔ checkout)
     */
    public static function settle(array $order, array $config): void
    {
        ShopPrepaidConfirmKernel::settle($order, $config);
    }
}

/**
 * هسته: ۱) فاکتور را Paid کن ۲) تلاش برای ساخت/وصل سرویس (هوک‌های اکتشافی + نقطهٔ توسعهٔ دستی).
 */
final class ShopPrepaidConfirmKernel
{
    /** مدل فاکتور XMPlus — در صورت تفاوت نام‌فضا اینجا را عوض کنید. */
    private const INVOICE_MODEL = 'App\\Application\\Models\\Invoice';

    /** متدهای نمونه روی Invoice برای رسیدن به Paid */
    private const PAY_INSTANCE_METHODS = [
        'confirmByReseller', 'confirmByAdmin', 'setPaid', 'markPaid', 'complete', 'finalizePayment',
        'payWithResellerBalance', 'paid', 'confirm', 'pay', 'checkout', 'executePay', 'payInvoice',
        'activateService', 'provisionService',
    ];

    /** بعد از Paid — ساخت/فعال‌سازی سرویس */
    private const FULFILL_INSTANCE_METHODS = [
        'activateServices', 'createService', 'createServices', 'issueService', 'fulfillInvoice', 'fulfill',
        'completeOrder', 'afterPaid', 'afterPaymentSuccess', 'processAfterPayment', 'postPayment',
        'deliverProduct', 'provisionSubscription', 'provisionService', 'addSubscription', 'buildUserService',
        'syncServices', 'handlePaymentSuccess', 'onGatewaySuccess', 'completePayment', 'generateSubscription',
    ];

    /** [کلاس, متد استاتیک] */
    private const STATIC_FULFILL = [
        ['App\\Application\\Services\\InvoiceService', 'fulfillPaidInvoice'],
        ['App\\Application\\Services\\InvoiceService', 'processPaidInvoice'],
        ['App\\Application\\Services\\InvoiceService', 'activateFromInvoice'],
        ['App\\Application\\Services\\OrderService', 'fulfillInvoice'],
        ['App\\Services\\InvoiceService', 'fulfillPaidInvoice'],
    ];

    private const CONTAINER_CLASSES = [
        'App\\Application\\Services\\InvoicePaymentService',
        'App\\Application\\Services\\PaymentService',
        'App\\Application\\Services\\OrderService',
        'App\\Application\\Services\\InvoiceService',
        'App\\Application\\Services\\BillingService',
        'App\\Services\\InvoiceService',
        'App\\Services\\OrderService',
        'App\\Services\\PaymentService',
    ];

    private const CONTAINER_METHODS = [
        'payInvoice', 'completeInvoice', 'fulfillInvoice', 'confirmInvoice', 'processPaidInvoice',
        'afterInvoicePaid', 'createServiceFromInvoice', 'handlePaidInvoice', 'settleInvoice',
        'processGatewayPayment', 'onInvoicePaid',
    ];

    /**
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $config
     */
    public static function settle(array $order, array $config): void
    {
        $gatewayId = self::numericGatewayId($order, $config);
        self::debugLog($config, 'settle:start', ['gateway_id' => $gatewayId, 'order_keys' => array_keys($order)]);

        $fqcn = self::INVOICE_MODEL;
        if (! class_exists($fqcn)) {
            throw new RuntimeException(
                "ShopPrepaidConfirmKernel: model {$fqcn} not found. Edit INVOICE_MODEL in ShopPrepaidConfirm.php."
            );
        }

        $invoice = self::loadInvoice($fqcn, $order);
        if ($invoice === null) {
            $hint = ShopPrepaidConfirmOrder::invoicePublicKey($order);

            throw new RuntimeException(
                'ShopPrepaidConfirmKernel: invoice not found (tried inv_id and invioce_id columns, then PK id). '
                .'public id='.($hint !== '' ? $hint : '(empty)').'.'
            );
        }

        $invId = self::invoiceInvIdFromModel($invoice);
        if ($invId === '') {
            throw new RuntimeException('ShopPrepaidConfirmKernel: loaded invoice has empty inv_id.');
        }
        self::debugLog($config, 'settle:invoice_loaded', ['inv_id' => $invId, 'status' => $invoice->status ?? null]);

        self::tryPayWithGatewayId($fqcn, $invId, $gatewayId);

        if (! self::rowIsPaid($invoice)) {
            $invoice = self::firstInvoiceByPublicKey($fqcn, $invId) ?? $invoice;
            self::markPaidBestEffort($fqcn, $invId, $invoice, $order, $gatewayId);
        }

        $invoice = self::firstInvoiceByPublicKey($fqcn, $invId);
        if ($invoice === null || ! self::rowIsPaid($invoice)) {
            throw new RuntimeException(
                'ShopPrepaidConfirmKernel: could not mark invoice '.$invId.' as paid. '
                .'See Invoice model and the same code path as /portal/invoice/query on your panel.'
            );
        }

        self::fulfillAfterPaid($fqcn, $invId, $order, $gatewayId);
        self::tryUtilityHelpers($invoice, $invId, $gatewayId);
        self::hookPanelFulfill($invoice, $invId, $order, $gatewayId, $config);

        $after = self::firstInvoiceByPublicKey($fqcn, $invId);
        self::debugLog($config, 'settle:after_hooks', [
            'inv_id' => $invId,
            'status' => $after->status ?? null,
            'serviceid' => $after->serviceid ?? ($after->service_id ?? null),
        ]);
    }

    /**
     * آخرین قلاب: منطق اختصاصی symmetricnet / XMPlus خودتان را اینجا صدا بزنید.
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $config
     */
    public static function hookPanelFulfill(object $invoice, string $invId, array $order, ?int $gatewayId, array $config): void
    {
        // symmetricnet: Guest\CallbackController نشان می‌دهد مسیر واقعی ساخت سرویس این فراخوانی است.
        self::tryInvoiceHelperPay($invoice, $config, $invId);
        self::tryConfiguredAfterPaidStatic($config, $invoice, $invId, $gatewayId);
        self::tryDomainInvoiceAfterPaidStatics($invoice, $invId, $gatewayId);
    }

    /**
     * از تنظیمات درگاه: after_paid_class + after_paid_method (متد استاتیک).
     *
     * @param  array<string, mixed>  $config
     */
    private static function tryConfiguredAfterPaidStatic(array $config, object $invoice, string $invId, ?int $gatewayId): void
    {
        $cls = trim((string) ($config['after_paid_class'] ?? ''));
        $method = trim((string) ($config['after_paid_method'] ?? 'handle'));
        if ($cls === '' || ! class_exists($cls) || ! is_callable([$cls, $method])) {
            return;
        }
        try {
            $rm = new ReflectionMethod($cls, $method);
            if (! $rm->isStatic()) {
                return;
            }
            self::invokeStaticByRequiredArity($cls, $method, $invoice, $invId, $gatewayId);
        } catch (Throwable $e) {
        }
    }

    /**
     * اگر روی پنل کلاس App\Domain\Invoice وجود داشته باشد، چند نام متد رایج را امتحان می‌کند.
     */
    private static function tryDomainInvoiceAfterPaidStatics(object $invoice, string $invId, ?int $gatewayId): void
    {
        $cls = 'App\\Domain\\Invoice';
        if (! class_exists($cls)) {
            return;
        }
        $candidates = [
            'afterInvoicePaid', 'handlePaidInvoice', 'provisionFromInvoice', 'createServiceFromInvoice',
            'confirmPaid', 'afterPaid', 'deliver', 'fulfill',
        ];
        foreach ($candidates as $method) {
            if (! is_callable([$cls, $method])) {
                continue;
            }
            try {
                $rm = new ReflectionMethod($cls, $method);
                if (! $rm->isStatic()) {
                    continue;
                }
                self::invokeStaticByRequiredArity($cls, $method, $invoice, $invId, $gatewayId);

                return;
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * @param  class-string  $cls
     */
    private static function invokeStaticByRequiredArity(string $cls, string $method, object $invoice, string $invId, ?int $gatewayId): void
    {
        $rm = new ReflectionMethod($cls, $method);
        $req = $rm->getNumberOfRequiredParameters();
        if ($req === 0) {
            $cls::$method();

            return;
        }
        if ($req === 1) {
            $cls::$method($invoice);

            return;
        }
        if ($req === 2) {
            $cls::$method($invoice, $invId);

            return;
        }
        if ($req === 3) {
            $cls::$method($invoice, $invId, $gatewayId ?? 0);

            return;
        }
    }

    /**
     * شناسهٔ عددی درگاه (برای متدهایی مثل pay($gatewayId) روی مدل فاکتور).
     *
     * @param  array<string, mixed>  $order
     * @param  array<string, mixed>  $config
     */
    private static function numericGatewayId(array $order, array $config): ?int
    {
        foreach (['gatewayid', 'gateway_id'] as $k) {
            if (isset($order[$k]) && is_numeric($order[$k])) {
                $n = (int) $order[$k];

                return $n > 0 ? $n : null;
            }
        }
        if (isset($config['id']) && is_numeric($config['id'])) {
            $n = (int) $config['id'];

            return $n > 0 ? $n : null;
        }

        return null;
    }

    /**
     * همان الویت Card2Card: order_id سپس سایر کلیدها؛ در نهایت id به‌عنوان کلید اصلی جدول.
     *
     * @param  class-string  $fqcn
     */
    private static function loadInvoice(string $fqcn, array $order): ?object
    {
        $invId = ShopPrepaidConfirmOrder::invoicePublicKey($order);
        if ($invId !== '') {
            $row = self::firstInvoiceByPublicKey($fqcn, $invId);
            if ($row !== null) {
                return $row;
            }
        }
        if (! empty($order['id']) && is_numeric($order['id']) && method_exists($fqcn, 'find')) {
            $pk = (int) $order['id'];
            if ($pk > 0) {
                $row = $fqcn::find($pk);

                return is_object($row) ? $row : null;
            }
        }

        return null;
    }

    /**
     * بسیاری از نصب‌های XMPlus ستون عمومی فاکتور را invioce_id می‌نامند (غلط املایی API) نه inv_id.
     *
     * @param  class-string  $fqcn
     */
    /**
     * کوئری پایه بدون Global Scope (برخی پنل‌ها scope روی user/status می‌گذارند و API درگاه ردیف را نمی‌بیند).
     *
     * @param  class-string  $fqcn
     */
    private static function invoiceQueryWithoutScopes(string $fqcn): object
    {
        if (is_subclass_of($fqcn, \Illuminate\Database\Eloquent\Model::class, true) && method_exists($fqcn, 'withoutGlobalScopes')) {
            return $fqcn::withoutGlobalScopes();
        }

        return $fqcn::query();
    }

    /**
     * XMPlus: خروجی API معمولاً invioce_id است؛ در DB گاهی فقط همان ستون پر است و inv_id خالی است.
     * نام‌های invioceid / trade_no برای فورک‌های قدیمی‌تر امتحان می‌شود.
     *
     * @param  class-string  $fqcn
     */
    private static function firstInvoiceByPublicKey(string $fqcn, string $publicId): ?object
    {
        if ($publicId === '' || ! method_exists($fqcn, 'query')) {
            return null;
        }
        $columns = ['invioce_id', 'inv_id', 'invioceid', 'invoice_id', 'trade_no', 'token'];
        foreach ($columns as $col) {
            try {
                $row = self::invoiceQueryWithoutScopes($fqcn)->where($col, $publicId)->first();
                if ($row !== null) {
                    return $row;
                }
            } catch (Throwable $e) {
            }
        }

        return null;
    }

    /**
     * @param  class-string  $fqcn
     */
    private static function updateInvoiceRowByPublicKey(string $fqcn, string $publicId, array $attrs): void
    {
        if ($publicId === '' || ! method_exists($fqcn, 'query')) {
            return;
        }
        $columns = ['invioce_id', 'inv_id', 'invioceid', 'invoice_id', 'trade_no', 'token'];
        foreach ($columns as $col) {
            try {
                $n = self::invoiceQueryWithoutScopes($fqcn)->where($col, $publicId)->limit(1)->update($attrs);
                if ($n > 0) {
                    return;
                }
            } catch (Throwable $e) {
            }
        }
    }

    private static function invoiceInvIdFromModel(object $invoice): string
    {
        $v = $invoice->inv_id ?? $invoice->invioce_id ?? null;
        if (is_string($v) || is_numeric($v)) {
            return trim((string) $v);
        }

        return '';
    }

    /**
     * بسیاری از پنل‌ها با یک متد pay(int $gatewayId) یا مشابه، هم Paid و هم سرویس را جلو می‌برند.
     *
     * مهم: اگر قبلاً با forcePaidRow فقط status/paid_date عوض شده باشد، فاکتور Paid است اما سرویس ساخته نشده.
     * در آن حالت باید همان pay($gatewayId) دوباره (یا برای اولین بار) صدا زده شود؛ پس پارامتر
     * $invokeEvenIfAlreadyPaid در فاز fulfillAfterPaid مقدار true می‌گیرد.
     *
     * @param  class-string  $fqcn
     */
    private static function tryPayWithGatewayId(string $fqcn, string $invId, ?int $gatewayId, bool $invokeEvenIfAlreadyPaid = false): void
    {
        if ($gatewayId === null || $gatewayId <= 0) {
            return;
        }
        $methodNames = ['pay', 'checkout', 'completePay', 'gatewayPay', 'payWithGateway', 'executeGatewayPay'];
        $inv = self::firstInvoiceByPublicKey($fqcn, $invId);
        if ($inv === null) {
            return;
        }
        if (! $invokeEvenIfAlreadyPaid && self::rowIsPaid($inv)) {
            return;
        }
        foreach ($methodNames as $m) {
            if (! method_exists($inv, $m)) {
                continue;
            }
            try {
                $rm = new ReflectionMethod($inv, $m);
                if ($rm->getNumberOfRequiredParameters() !== 1) {
                    continue;
                }
                $inv->{$m}($gatewayId);
            } catch (Throwable $e) {
            }
            $inv = self::firstInvoiceByPublicKey($fqcn, $invId);
            if ($inv !== null && self::rowIsPaid($inv) && self::invoiceHasServiceId($inv)) {
                return;
            }
            if ($inv !== null && self::rowIsPaid($inv) && ! $invokeEvenIfAlreadyPaid) {
                return;
            }
        }

        if ($invokeEvenIfAlreadyPaid) {
            self::tryInvoiceSingleIntGatewayMethods($fqcn, $invId, $gatewayId);
        }
    }

    /**
     * متدهای نمونه با دقیقاً یک پارامتر اجباری (معمولاً gateway id) — وقتی نام متد از لیست ثابت نیست.
     *
     * @param  class-string  $fqcn
     */
    private static function tryInvoiceSingleIntGatewayMethods(string $fqcn, string $invId, int $gatewayId): void
    {
        try {
            $ref = new ReflectionClass($fqcn);
        } catch (Throwable $e) {
            return;
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            if ($rm->isStatic() || $rm->getNumberOfRequiredParameters() !== 1) {
                continue;
            }
            if ($rm->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }
            $name = $rm->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }
            if (preg_match('/pay|gateway|checkout|complete|confirm|finalize|settle|activate|provision|execute/i', $name) !== 1) {
                continue;
            }
            if (preg_match('/^(get|set|is|has|to|new|delete|find|all|where|query)/i', $name) === 1) {
                continue;
            }

            $inv = self::firstInvoiceByPublicKey($fqcn, $invId);
            if ($inv === null) {
                return;
            }
            try {
                $inv->{$name}($gatewayId);
            } catch (Throwable $e) {
            }
            $inv = self::firstInvoiceByPublicKey($fqcn, $invId);
            if ($inv !== null && self::invoiceHasServiceId($inv)) {
                return;
            }
        }
    }

    private static function invoiceHasServiceId(object $invoice): bool
    {
        foreach (['serviceid', 'service_id', 'sid'] as $k) {
            if (! isset($invoice->{$k})) {
                continue;
            }
            $v = $invoice->{$k};
            if ($v === null || $v === '') {
                continue;
            }
            if (is_numeric($v) && (int) $v > 0) {
                return true;
            }
            if (is_string($v) && trim($v) !== '' && strtolower(trim($v)) !== 'null') {
                return true;
            }
        }

        return false;
    }

    /**
     * کلاس Helpers در نمونهٔ شما (Card2Card) ایمپورت شده؛ احتمالاً تکمیل پرداخت از اینجا هم صدا زده می‌شود.
     */
    private static function tryUtilityHelpers(object $invoice, string $invId, ?int $gatewayId): void
    {
        $cls = 'App\\Utility\\Helpers';
        if (! class_exists($cls)) {
            return;
        }
        $candidates = [
            'invoicePayComplete', 'InvoicePayComplete', 'completeInvoicePayment', 'gatewayInvoicePaid',
            'payInvoiceComplete', 'invoiceNotifySuccess', 'invoicePaid', 'paidInvoice', 'finishInvoice',
        ];
        foreach ($candidates as $m) {
            if (! is_callable([$cls, $m])) {
                continue;
            }
            try {
                $rm = new ReflectionMethod($cls, $m);
                if (! $rm->isStatic()) {
                    continue;
                }
                $req = $rm->getNumberOfRequiredParameters();
                if ($req === 1) {
                    try {
                        $cls::$m($invoice);
                    } catch (Throwable $e) {
                        $cls::$m($invId);
                    }
                } elseif ($req === 2 && $gatewayId !== null && $gatewayId > 0) {
                    $cls::$m($invoice, $gatewayId);
                }
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * مسیر واقعی observed روی symmetricnet:
     * \App\Application\Controller\Guest\CallbackController::handle()
     *   $service = InvoiceHelper::pay($invoice, $user)
     */
    private static function tryInvoiceHelperPay(object $invoice, array $config, string $invId): void
    {
        $helper = 'App\\Utility\\InvoiceHelper';
        $userModel = 'App\\Application\\Models\\User';
        if (! class_exists($helper) || ! is_callable([$helper, 'pay']) || ! class_exists($userModel)) {
            self::debugLog($config, 'invoice_helper:missing', [
                'inv_id' => $invId,
                'helper_exists' => class_exists($helper),
                'helper_pay_callable' => is_callable([$helper, 'pay']),
                'user_model_exists' => class_exists($userModel),
            ]);
            return;
        }

        $uidRaw = $invoice->userid ?? $invoice->user_id ?? null;
        if (! is_numeric($uidRaw) || (int) $uidRaw <= 0 || ! method_exists($userModel, 'find')) {
            self::debugLog($config, 'invoice_helper:invalid_uid', ['inv_id' => $invId, 'uid_raw' => $uidRaw]);
            return;
        }

        try {
            $user = $userModel::find((int) $uidRaw);
            if (! is_object($user)) {
                self::debugLog($config, 'invoice_helper:user_not_found', ['inv_id' => $invId, 'uid' => (int) $uidRaw]);
                return;
            }
            $ret = $helper::pay($invoice, $user);
            self::debugLog($config, 'invoice_helper:pay_called', [
                'inv_id' => $invId,
                'uid' => (int) $uidRaw,
                'ret_type' => gettype($ret),
                'ret_is_object' => is_object($ret),
                'ret_is_array' => is_array($ret),
            ]);
        } catch (Throwable $e) {
            self::debugLog($config, 'invoice_helper:exception', ['inv_id' => $invId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * برای دیباگ عملی روی پنل XMPlus: در config درگاه مقدار debug_log را 1/true بگذارید.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private static function debugLog(array $config, string $message, array $context = []): void
    {
        $enabledRaw = $config['debug_log'] ?? false;
        $enabled = $enabledRaw === true
            || $enabledRaw === 1
            || $enabledRaw === '1'
            || (is_string($enabledRaw) && strcasecmp($enabledRaw, 'true') === 0);
        if (! $enabled) {
            return;
        }

        try {
            error_log('ShopPrepaidConfirm DEBUG: '.$message.' '.json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $e) {
        }
    }

    private static function markPaidBestEffort(string $fqcn, string $invId, object $invoice, array $order, ?int $gatewayId): void
    {
        self::tryPayWithGatewayId($fqcn, $invId, $gatewayId);

        self::tryMethodsOnInvoice($fqcn, $invId, self::PAY_INSTANCE_METHODS);

        if (self::reloadPaid($fqcn, $invId)) {
            return;
        }

        self::reflectInstanceMethods($fqcn, $invId, '/confirm|complete|paid|pay|approve|finalize|settle|activate|provision|checkout|execute/i', '/^(get|set|is|has|to|new|create|delete|find|all|where|query)/i');

        if (self::reloadPaid($fqcn, $invId)) {
            return;
        }

        self::forcePaidRow($fqcn, $invId, $invoice, $order);
    }

    /**
     * @param  class-string  $fqcn
     */
    private static function fulfillAfterPaid(string $fqcn, string $invId, array $order, ?int $gatewayId): void
    {
        $invoice = self::firstInvoiceByPublicKey($fqcn, $invId);
        if ($invoice === null || ! self::rowIsPaid($invoice)) {
            return;
        }

        // اگر فقط ردیف «دستی» Paid شده، pay($gatewayId) قبل‌تر به‌خاطر rowIsPaid اجرا نشده است.
        self::tryPayWithGatewayId($fqcn, $invId, $gatewayId, true);
        self::tryMethodsOnInvoice($fqcn, $invId, self::FULFILL_INSTANCE_METHODS);

        try {
            if (method_exists($invoice, 'refresh')) {
                $invoice->refresh();
            }
        } catch (Throwable $e) {
        }

        self::reflectInstanceMethods($fqcn, $invId, '/service|subscription|fulfill|deliver|provision|activate|issue|package|product|order|user.?service/i', '/^(get|set|is|has|to|new|delete|find|all|where|query|attribute)/i');
        self::tryStaticFulfill($invId, $invoice);
        self::tryContainerFulfill($invoice, $invId);
    }

    /**
     * قبل از هر متد مدل را از DB تازه می‌کند تا وضعیت هم‌خوان بماند.
     *
     * @param  class-string  $fqcn
     * @param  list<string>  $methods
     */
    private static function tryMethodsOnInvoice(string $fqcn, string $invId, array $methods): void
    {
        foreach ($methods as $method) {
            $current = self::firstInvoiceByPublicKey($fqcn, $invId);
            if ($current === null) {
                return;
            }
            if (! method_exists($current, $method)) {
                continue;
            }
            try {
                $current->{$method}();
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * @param  class-string  $fqcn
     */
    private static function reflectInstanceMethods(string $fqcn, string $invId, string $mustMatch, string $rejectPrefix): void
    {
        try {
            $ref = new ReflectionClass($fqcn);
        } catch (Throwable $e) {
            return;
        }

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $rm) {
            if ($rm->isStatic() || $rm->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            if ($rm->getDeclaringClass()->getName() !== $fqcn) {
                continue;
            }
            $name = $rm->getName();
            if (str_starts_with($name, '__')) {
                continue;
            }
            if (preg_match($rejectPrefix, $name) === 1) {
                continue;
            }
            if (preg_match($mustMatch, $name) !== 1) {
                continue;
            }

            $working = self::firstInvoiceByPublicKey($fqcn, $invId);
            if ($working === null) {
                return;
            }
            try {
                $working->{$name}();
            } catch (Throwable $e) {
            }
        }
    }

    /**
     * @param  class-string  $fqcn
     */
    private static function reloadPaid(string $fqcn, string $invId): bool
    {
        $inv = self::firstInvoiceByPublicKey($fqcn, $invId);

        return $inv !== null && self::rowIsPaid($inv);
    }

    private static function rowIsPaid(object $invoice): bool
    {
        $st = $invoice->status ?? null;
        if ($st === 1 || $st === '1' || $st === true) {
            return true;
        }

        return is_string($st) && strcasecmp($st, 'paid') === 0;
    }

    /**
     * @param  class-string  $fqcn
     * @param  array<string, mixed>  $order
     */
    private static function forcePaidRow(string $fqcn, string $invId, object $invoice, array $order): void
    {
        $attrs = ['status' => 1];
        $now = date('Y-m-d H:i:s');

        foreach (['paid_date', 'paid_at', 'pay_time', 'paid_time'] as $col) {
            if (self::modelHasColumn($invoice, $col)) {
                $attrs[$col] = $now;
                break;
            }
        }

        $amount = $order['total_amount'] ?? $order['total'] ?? null;
        if ($amount !== null && $amount !== '') {
            foreach (['paid_amount', 'amount_paid', 'pay_amount'] as $col) {
                if (self::modelHasColumn($invoice, $col)) {
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
        }

        self::updateInvoiceRowByPublicKey($fqcn, $invId, $attrs);
    }

    private static function modelHasColumn(object $invoice, string $key): bool
    {
        if (method_exists($invoice, 'getAttributes')) {
            $a = $invoice->getAttributes();

            return is_array($a) && array_key_exists($key, $a);
        }

        return property_exists($invoice, $key);
    }

    private static function tryStaticFulfill(string $invId, object $invoice): void
    {
        foreach (self::STATIC_FULFILL as [$cls, $meth]) {
            if (! class_exists($cls) || ! method_exists($cls, $meth)) {
                continue;
            }
            try {
                $rm = new ReflectionMethod($cls, $meth);
                if (! $rm->isStatic()) {
                    continue;
                }
                $req = $rm->getNumberOfRequiredParameters();
                if ($req === 0) {
                    $cls::$meth();
                } elseif ($req === 1) {
                    try {
                        $cls::$meth($invoice);
                    } catch (Throwable $e) {
                        $cls::$meth($invId);
                    }
                }
            } catch (Throwable $e) {
            }
        }
    }

    private static function tryContainerFulfill(object $invoice, string $invId): void
    {
        if (! function_exists('app')) {
            return;
        }
        try {
            $app = app();
        } catch (Throwable $e) {
            return;
        }

        foreach (self::CONTAINER_CLASSES as $cls) {
            if (! class_exists($cls)) {
                continue;
            }
            try {
                $svc = $app->make($cls);
            } catch (Throwable $e) {
                continue;
            }
            if (! is_object($svc)) {
                continue;
            }

            foreach (self::CONTAINER_METHODS as $m) {
                if (! method_exists($svc, $m)) {
                    continue;
                }
                try {
                    $rm = new ReflectionMethod($svc, $m);
                    if (! $rm->isPublic() || $rm->getNumberOfRequiredParameters() !== 1) {
                        continue;
                    }
                    try {
                        $svc->{$m}($invoice);
                    } catch (Throwable $e) {
                        $svc->{$m}($invId);
                    }
                } catch (Throwable $e) {
                }
            }
        }
    }
}
