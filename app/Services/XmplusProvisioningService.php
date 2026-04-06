<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\XmplusInvoiceDatabaseSyncService;
use App\Services\XmplusPackageAwareRenewalService;
use App\Support\XmplusGatewayTelegram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * ساخت/تمدید سرویس در XMPlus از طریق Client API پس از پرداخت در سایت یا ربات.
 */
class XmplusProvisioningService
{
    /** اگر فاکتور Paid است و account/info هنوز services خالی است، بعد از این تعداد تلاش polling متوقف و پیام راهنما برگردانده می‌شود. */
    private const POLL_EARLY_FALLBACK_WHEN_PAID_NO_SERVICES_AFTER = 12;

    /** Card2Card + همگام‌سازی MySQL معمولاً فقط ردیف را Paid می‌کند و سرویس نمی‌سازد — زودتر از حالت عادی fallback می‌کنیم. */
    private const POLL_EARLY_FALLBACK_CARD2CARD_DB_SYNC_PAID_NO_SERVICE_AFTER = 4;

    /** ShopPrepaidConfirm اگر فقط DB را Paid کند بدون اجرای منطق ساخت سرویس، همان وضعیت Card2Card است — polling را کوتاه می‌کنیم و پیام هدایت می‌دهیم. */
    private const POLL_EARLY_FALLBACK_SHOP_PREPAID_PAID_NO_SERVICE_AFTER = 6;

    public static function fromSettings(Collection $settings): XmplusService
    {
        $base = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');
        $key = (string) $settings->get('xmplus_client_api_key', '');
        if ($base === '' || $key === '') {
            throw new InvalidArgumentException('XMPlus: آدرس پنل (xmplus_panel_url) یا Client API Key در تنظیمات خالی است.');
        }

        return new XmplusService($base, $key);
    }

    public static function resolvePackageId(Plan $plan, Collection $settings): int
    {
        $pid = $plan->xmplus_package_id ?? $settings->get('xmplus_default_package_id');
        $pid = $pid !== null && $pid !== '' ? (int) $pid : 0;
        if ($pid <= 0) {
            throw new InvalidArgumentException('XMPlus: شناسه بسته (pid) برای این پلن مشخص نیست. در ویرایش پلن «شناسه بسته XMPlus» یا در تنظیمات «شناسه بسته پیش‌فرض» را بگذارید.');
        }

        return $pid;
    }

    public static function resolveBilling(Plan $plan): string
    {
        $b = $plan->xmplus_billing;
        if (is_string($b) && $b !== '') {
            return $b;
        }
        $days = (int) $plan->duration_days;

        return match (true) {
            $days <= 35 => 'month',
            $days <= 100 => 'quater',
            $days <= 200 => 'semiannual',
            default => 'annual',
        };
    }

    /**
     * @return array{
     *   final_config?: string,
     *   panel_username?: string,
     *   panel_client_id?: ?string,
     *   credentials_message?: ?string,
     *   plain_password?: ?string,
     *   phase?: string,
     *   order_id?: int
     * }
     */
    /**
     * @param  bool  $shopPaymentAlreadyCollected  مشتری در VPNMarket پرداخت کرده (کیف پول، Plisio، تأیید ادمین، …) — فاکتور XMPlus فقط با «درگاه خودکار» (تسویهٔ فروشنده) بسته می‌شود، نه پرداخت دوم توسط مشتری.
     */
    public static function provisionPurchase(
        Collection $settings,
        User $user,
        Plan $plan,
        Order $order,
        bool $isRenewal,
        ?Order $originalOrder,
        bool $shopPaymentAlreadyCollected = false
    ): array {
        $lock = Cache::lock('xmplus_provision_order:'.$order->id, 180);
        if (! $lock->get()) {
            throw new RuntimeException(
                'XMPlus: این سفارش هم‌اکنون در حال پردازش است. چند ثانیه صبر کنید، لیست را رفرش کنید؛ فقط اگر سفارش هنوز «در انتظار» بود دوباره «تایید و اجرا» بزنید تا فاکتور تکراری در پنل ساخته نشود.'
            );
        }
        try {
            $api = self::fromSettings($settings);
            $pid = self::resolvePackageId($plan, $settings);
            $billing = self::resolveBilling($plan);
            $panelBase = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');
            $aff = (string) ($settings->get('xmplus_affiliate_code') ?? '');

            $api->log('info', 'XMPlus provisionPurchase شروع', [
                'step' => 'provision_start',
                'user_id' => $user->id,
                'order_id' => $order->id,
                'plan_id' => $plan->id,
                'is_renewal' => $isRenewal,
                'pid' => $pid,
                'billing' => $billing,
                'shop_payment_collected' => $shopPaymentAlreadyCollected,
            ]);

            if ($isRenewal) {
                return self::doRenewal($api, $settings, $user, $plan, $order, $originalOrder, $pid, $panelBase, $shopPaymentAlreadyCollected);
            }

            return self::doNewPurchase($api, $settings, $user, $plan, $order, $pid, $billing, $aff, $panelBase, $shopPaymentAlreadyCollected);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $invoiceView
     */
    protected static function invoiceViewResponseIsPaid(array $invoiceView): bool
    {
        $inv = $invoiceView['invoice'] ?? null;
        if (! is_array($inv)) {
            return false;
        }

        return is_string($inv['status'] ?? null) && strcasecmp((string) $inv['status'], 'Paid') === 0;
    }

    /**
     * @param  array<string, mixed>  $invoiceView
     */
    protected static function invoiceViewResponseHasServiceId(array $invoiceView): bool
    {
        $inv = $invoiceView['invoice'] ?? null;
        if (! is_array($inv)) {
            return false;
        }

        foreach (['serviceid', 'service_id', 'sid'] as $k) {
            $v = $inv[$k] ?? null;
            if ($v === null || $v === '') {
                continue;
            }
            if (is_numeric($v) && (int) $v > 0) {
                return true;
            }
            if (is_string($v) && trim($v) !== '' && strtolower(trim($v)) !== 'null' && trim($v) !== '0') {
                return true;
            }
        }

        return false;
    }

    /**
     * پس از انتخاب درگاه توسط کاربر در ربات: invoice/pay و polling تا sublink.
     *
     * @param  array<string, mixed>  $ctx
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string}
     */
    public static function payInvoiceWithGatewayAndPoll(
        XmplusService $api,
        array $ctx,
        int $gatewayId,
        Collection $settings,
        ?string $telegramChatId
    ): array {
        $email = (string) ($ctx['email'] ?? '');
        $passwd = (string) ($ctx['passwd'] ?? '');
        $invid = (string) ($ctx['invid'] ?? '');
        $pid = (int) ($ctx['pid'] ?? 0);
        $knownSid = isset($ctx['known_sid']) ? (int) $ctx['known_sid'] : null;
        if ($knownSid !== null && $knownSid <= 0) {
            $knownSid = null;
        }
        if ($email === '' || $passwd === '' || $invid === '' || $pid <= 0) {
            throw new RuntimeException('XMPlus: بافتار ناقص برای پرداخت فاکتور (کش منقضی یا نامعتبر).');
        }

        try {
            $payResponse = $api->invoicePay($email, $passwd, $invid, $gatewayId);
            $api->log('info', 'XMPlus invoice/pay (انتخاب کاربر)', ['step' => 'invoice_pay_user_gateway', 'gatewayid' => $gatewayId]);
        } catch (\Throwable $e) {
            throw new RuntimeException('XMPlus invoice/pay ناموفق: '.$e->getMessage(), 0, $e);
        }

        if (! is_array($payResponse)) {
            $payResponse = [];
        }

        if ($telegramChatId !== null && $telegramChatId !== '') {
            XmplusGatewayTelegram::sendInvoicePayInstructions($payResponse, $telegramChatId, $settings);
        }

        $async = self::invoicePayLooksAsync($payResponse);
        $maxAttempts = $async ? 72 : 18;
        $sleepSeconds = $async ? 5 : 2;

        $panelBasePoll = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');
        $pollFb = ['panel_base' => $panelBasePoll, 'order_id' => (int) ($ctx['order_id'] ?? 0)];
        $poll = self::pollForSublink($api, $email, $passwd, $invid, $pid, $knownSid, true, $maxAttempts, $sleepSeconds, false, $pollFb);
        $sid = $poll['sid'];

        return [
            'final_config' => $poll['sublink'],
            'panel_username' => $email,
            'panel_client_id' => $sid !== null ? (string) $sid : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $pay
     */
    protected static function invoicePayLooksAsync(array $pay): bool
    {
        if (! empty($pay['qrcode']) && is_string($pay['qrcode'])) {
            return true;
        }
        $d = $pay['data'] ?? null;
        if (is_string($d) && $d !== '') {
            return true;
        }
        if (is_array($d)) {
            // Stripe و مشابه: PaymentIntent تا زمان تکمیل کارت در حالت انتظار است
            if (($d['object'] ?? '') === 'payment_intent' || isset($d['client_secret'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * پاسخ invoice/pay یعنی مشتری باید جدا در XMPlus/درگاه پرداخت کند (PayPal، لینک https، …) —
     * وقتی پول از قبل در فروشگاه گرفته شده، باید درگاه «موجودی/اعتبار نماینده» استفاده شود؛ به‌روزرسانی دستی DB فاکتور را Paid نشان می‌دهد بدون ساخت سرویس.
     *
     * @param  array<string, mixed>  $pay
     */
    protected static function invoicePayLooksCustomerSideCheckout(array $pay): bool
    {
        if (! self::invoicePayLooksAsync($pay)) {
            return false;
        }
        $gw = strtolower((string) ($pay['gateway'] ?? ''));
        // نباید از needle عمومی «card» استفاده کرد — «Card2Card» شامل card است ولی درگاه کارت‌به‌کارت پنل است نه StripeCard.
        foreach (['paypal', 'stripe', 'wechat', 'alipay'] as $needle) {
            if ($gw !== '' && str_contains($gw, $needle)) {
                return true;
            }
        }
        $d = $pay['data'] ?? null;
        if (is_string($d) && $d !== '' && preg_match('#^https?://#i', $d) === 1) {
            return true;
        }

        return false;
    }

    /**
     * درگاه Card2Card پنل: invoice/pay معمولاً QR می‌دهد و فاکتور تا تأیید در XMPlus Pending می‌ماند.
     *
     * @param  array<string, mixed>  $pay
     */
    protected static function invoicePayGatewayIsCard2Card(array $pay): bool
    {
        $g = strtolower(trim((string) ($pay['gateway'] ?? '')));

        return $g === 'card2card' || str_contains($g, 'card2card');
    }

    /**
     * درگاه سفارشی «پرداخت از پیش توسط فروشگاه» (اسنیپت ShopPrepaidConfirm روی پنل).
     *
     * @param  array<string, mixed>  $pay
     */
    protected static function invoicePayGatewayIsShopPrepaidConfirm(array $pay): bool
    {
        $g = strtolower(trim((string) ($pay['gateway'] ?? '')));

        return $g === 'shopprepaidconfirm' || str_contains($g, 'shopprepaid');
    }

    /**
     * آیا پاسخ invoice/pay از نظر API موفق بوده (قبل از polling).
     *
     * @param  array<string, mixed>  $pay
     */
    protected static function invoicePayResponseLooksSuccessful(array $pay): bool
    {
        if ($pay === []) {
            return false;
        }
        if (isset($pay['code']) && (int) $pay['code'] !== 100) {
            return false;
        }
        $st = strtolower((string) ($pay['status'] ?? ''));
        if (in_array($st, ['fail', 'failed', 'error'], true)) {
            return false;
        }
        if ((int) ($pay['code'] ?? 0) === 100) {
            return true;
        }
        if (in_array($st, ['success', 'sucess'], true)) {
            return true;
        }

        return isset($pay['orderid']) || array_key_exists('data', $pay);
    }

    /**
     * برخی پنل‌ها (مثلاً با ShopPrepaidConfirm) برای POST /api/client/invoice/pay HTTP 200 می‌دهند ولی بدنه JSON خالی است؛
     * در لاگ VPNMarket به‌صورت {"_raw":""} دیده می‌شود. در این حالت فقط با invoice/view می‌توان تأیید کرد فاکتور بسته شده یا نه.
     *
     * @param  array<string, mixed>  $pay
     */
    protected static function invoicePayResponseBodyEmptyOrUnstructured(array $pay): bool
    {
        if ($pay === []) {
            return true;
        }
        $hasStructured = isset($pay['code']) || isset($pay['status']) || isset($pay['data'])
            || isset($pay['ret']) || isset($pay['gateway']) || isset($pay['message'])
            || isset($pay['orderid']) || isset($pay['invid']);
        if ($hasStructured) {
            return false;
        }
        if (array_key_exists('_raw', $pay)) {
            return trim((string) $pay['_raw']) === '';
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null  آرایهٔ pay مصنوعی در صورت تأیید Paid، وگرنه null
     */
    protected static function recoverShopInvoicePayAfterEmptyResponse(
        XmplusService $api,
        string $email,
        string $passwd,
        string $invid,
        array $pay
    ): ?array {
        if (! self::invoicePayResponseBodyEmptyOrUnstructured($pay)) {
            return null;
        }
        $attempts = 8;
        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0) {
                usleep(400_000);
            }
            try {
                $view = $api->invoiceView($email, $passwd, $invid);
                if (self::invoiceViewResponseIsPaid($view)) {
                    $api->log('info', 'XMPlus invoice/pay بدون JSON قابل‌اعتماد بود؛ invoice/view فاکتور را Paid گزارش کرد — ادامه با polling', [
                        'step' => 'invoice_pay_empty_body_recovered',
                        'invid' => $invid,
                        'attempt' => $i + 1,
                    ]);

                    return array_merge($pay, [
                        'code' => 100,
                        'status' => 'success',
                        'gateway' => 'ShopPrepaidConfirm',
                        '_empty_pay_body_recovered_via_invoice_view' => true,
                    ]);
                }
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus invoice/view هنگام بازیابی پس از pay خالی', [
                    'invid' => $invid,
                    'attempt' => $i + 1,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @param  callable(): array<string, mixed>  $invoicePay
     * @return array<string, mixed>
     */
    protected static function runInvoicePayStrictForShopCollected(
        XmplusService $api,
        callable $invoicePay,
        string $email,
        string $passwd,
        string $invid
    ): array {
        try {
            $pay = $invoicePay();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'XMPlus invoice/pay خطا داد؛ فاکتور در پنل باز می‌ماند. درگاه «شناسه پرداخت خودکار فاکتور» و Client API را بررسی کنید. پیام: '.$e->getMessage(),
                0,
                $e
            );
        }
        if (self::invoicePayResponseLooksSuccessful($pay)) {
            return $pay;
        }

        // برخی نصب‌های XMPlus در اولین فراخوانی invoice/pay بدنه خالی می‌دهند و حتی Pending می‌مانند.
        // در این حالت یک retry کنترل‌شده روی خود invoice/pay انجام می‌دهیم (نه فقط invoice/view).
        if (self::invoicePayResponseBodyEmptyOrUnstructured($pay)) {
            $maxPayRetries = 3;
            for ($retry = 1; $retry <= $maxPayRetries; $retry++) {
                usleep(400_000);
                try {
                    $view = $api->invoiceView($email, $passwd, $invid);
                    if (self::invoiceViewResponseIsPaid($view)) {
                        $api->log('info', 'XMPlus invoice/pay خالی بود اما invoice/view آن را Paid گزارش کرد (بدون retry بیشتر)', [
                            'step' => 'invoice_pay_empty_body_paid_before_retry',
                            'invid' => $invid,
                            'retry' => $retry,
                        ]);

                        return array_merge($pay, [
                            'code' => 100,
                            'status' => 'success',
                            'gateway' => 'ShopPrepaidConfirm',
                            '_empty_pay_body_recovered_via_invoice_view' => true,
                        ]);
                    }
                } catch (\Throwable $e) {
                    $api->log('warning', 'XMPlus invoice/view قبل از retry invoice/pay خطا', [
                        'invid' => $invid,
                        'retry' => $retry,
                        'error' => $e->getMessage(),
                    ]);
                }

                try {
                    $payRetry = $invoicePay();
                    if (self::invoicePayResponseLooksSuccessful($payRetry)) {
                        $api->log('info', 'XMPlus invoice/pay پس از retry موفق شد', [
                            'step' => 'invoice_pay_retry_success',
                            'invid' => $invid,
                            'retry' => $retry,
                        ]);

                        return $payRetry;
                    }
                    if (! self::invoicePayResponseBodyEmptyOrUnstructured($payRetry)) {
                        $pay = $payRetry;
                        break;
                    }
                    $pay = $payRetry;
                    $api->log('warning', 'XMPlus invoice/pay retry هنوز بدنه خالی داد', [
                        'step' => 'invoice_pay_retry_still_empty',
                        'invid' => $invid,
                        'retry' => $retry,
                    ]);
                } catch (\Throwable $e) {
                    $api->log('warning', 'XMPlus invoice/pay retry خطا داد', [
                        'step' => 'invoice_pay_retry_error',
                        'invid' => $invid,
                        'retry' => $retry,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $recovered = self::recoverShopInvoicePayAfterEmptyResponse($api, $email, $passwd, $invid, $pay);
        if ($recovered !== null) {
            return $recovered;
        }

        throw new RuntimeException(
            'XMPlus invoice/pay پاسخ موفق شناخته نشد؛ فاکتور احتمالاً Pending مانده. پاسخ خام: '.json_encode($pay, JSON_UNESCAPED_UNICODE)
            .' — اگر بدنه خالی است، در پنل خروجی JSON متد pay() درگاه ShopPrepaidConfirm را درست کنید؛ در غیر این صورت VPNMarket با invoice/view هم سعی می‌کند Paid بودن را تشخیص دهد.'
        );
    }

    protected static function shouldOfferTelegramGatewayPicker(Collection $settings, User $user): bool
    {
        if (! filter_var($settings->get('xmplus_telegram_gateway_picker', true), FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        $chat = $user->telegram_chat_id;

        return $chat !== null && $chat !== '';
    }

    /**
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string, credentials_message: ?string, plain_password: ?string}|array{phase: string, order_id: int, credentials_message: ?string}
     */
    protected static function doNewPurchase(
        XmplusService $api,
        Collection $settings,
        User $user,
        Plan $plan,
        Order $order,
        int $pid,
        string $billing,
        string $aff,
        string $panelBase,
        bool $shopPaymentAlreadyCollected = false,
        bool $allowXmplusStaleCredentialReset = true
    ): array {
        $domain = trim((string) $settings->get('xmplus_email_domain', ''));
        if ($domain === '') {
            throw new InvalidArgumentException('XMPlus: دامنه ایمیل (xmplus_email_domain) در تب XMPlus را پر کنید (مثال: shop.example.com).');
        }
        $domain = ltrim($domain, '@');

        $email = $user->xmplus_client_email;
        $passwdPlain = null;
        $credentialsMessage = null;

        if (! is_string($email) || $email === '') {
            // یک ایمیل پایدار به‌ازای هر کاربر سایت (همان telegram user id در دیتابیس)؛ همهٔ خریدهای بعدی همان حساب XMPlus را استفاده می‌کنند.
            $email = 'tg'.$user->id.'@'.$domain;
            $passwdPlain = Str::password(16, symbols: false);
            $name = self::xmplusDisplayName($user);

            $sendCode = filter_var($settings->get('xmplus_send_register_code', false), FILTER_VALIDATE_BOOLEAN);
            if ($sendCode) {
                $api->registerSendCode($name, $email);
            }

            $regCode = (string) ($settings->get('xmplus_registration_code') ?? '');
            $reg = $api->register($name, $email, $passwdPlain, $regCode, $aff);
            if (! self::apiOk($reg)) {
                if (self::apiIsEmailAlreadyRegistered($reg)) {
                    // اگر ایمیل قبلاً ثبت شده و رمز در VPNMarket موجود است، از همان استفاده کن
                    $existingPassword = $user->xmplus_client_password;
                    if (is_string($existingPassword) && $existingPassword !== '') {
                        $api->log('info', 'XMPlus: کاربر قبلاً register شده، از رمز موجود استفاده می‌شود', [
                            'email' => $email,
                        ]);
                        $passwdPlain = $existingPassword;
                        // از loop register خارج شو و ادامه بده
                    } else {
                        // کاربر در XMPlus موجود است اما رمز ذخیره نشده
                        // سعی کن از orders موفق قبلی password را پیدا کنی
                        $successfulOrder = Order::where('user_id', $user->id)
                            ->where('status', 'paid')
                            ->whereNotNull('panel_username')
                            ->whereNotNull('panel_client_id')
                            ->latest()
                            ->first();
                        
                        if ($successfulOrder && $successfulOrder->panel_username === $email) {
                            // این کاربر قبلاً خرید موفق داشته، اما password در user table ذخیره نشده
                            // احتمالاً در زمان تمدید استفاده شده
                            $api->log('warning', 'XMPlus: کاربر موجود است اما password در user ذخیره نشده. لطفاً password را در admin panel وارد کنید.', [
                                'email' => $email,
                                'user_id' => $user->id,
                            ]);
                        }
                        
                        throw new RuntimeException(
                            'XMPlus: ایمیل '.$email.' از قبل در پنل ثبت است ولی فروشگاه هنوز رمز Client API این کاربر را ندارد. '
                            .'لطفاً از پنل Admin XMPlus، password کاربر '.$email.' را ببینید یا reset کنید، سپس در پروفایل کاربر VPNMarket (User ID: '.$user->id.') ذخیره کنید. '
                            .'یا کاربر را در XMPlus حذف کنید تا دوباره ثبت‌نام شود.'
                        );
                    }
                } else {
                    throw new RuntimeException('XMPlus ثبت‌نام ناموفق: '.json_encode($reg, JSON_UNESCAPED_UNICODE));
                }
            } else {
                // ثبت‌نام موفق بود، رمز جدید را ذخیره کن
                $user->forceFill([
                    'xmplus_client_email' => $email,
                    'xmplus_client_password' => $passwdPlain,
                ])->save();
                
                // بلافاصله flush کن تا مطمئن شوی ذخیره شده
                $api->log('info', 'XMPlus: password ذخیره شد', [
                    'email' => $email,
                    'user_id' => $user->id,
                ]);
            }

            $credentialsMessage = self::formatCredentialsMessage($email, $passwdPlain, $panelBase);
            $api->log('info', 'XMPlus کاربر جدید ثبت شد', ['step' => 'register_ok', 'email' => $email]);
        } else {
            $passwdPlain = $user->xmplus_client_password;
            if (! is_string($passwdPlain) || $passwdPlain === '') {
                throw new RuntimeException('XMPlus: ایمیل کاربر در دیتابیس هست اما رمز ذخیره‌شده نیست؛ امکان ساخت فاکتور نیست.');
            }
            $api->log('info', 'XMPlus استفاده از حساب موجود', ['step' => 'existing_user', 'email' => $email]);
        }

        $gatewayId = $settings->get('xmplus_auto_pay_gateway_id');
        $autoPayConfigured = $gatewayId !== null && $gatewayId !== '' && is_numeric((string) $gatewayId);

        $invid = trim((string) ($order->xmplus_inv_id ?? ''));
        $reuseInvoice = $invid !== '' && trim((string) ($order->config_details ?? '')) === '';

        if (! $reuseInvoice) {
            $inv = self::invoiceCreateWithBillingFallback($api, $email, $passwdPlain, $pid, $billing, (int) $order->id);
            if (! self::apiOk($inv)) {
                if (
                    $allowXmplusStaleCredentialReset
                    && self::apiIsEmailNotFoundOnClient($inv)
                    && is_string($user->xmplus_client_email)
                    && $user->xmplus_client_email !== ''
                ) {
                    $api->log('warning', 'XMPlus invoice/create: ایمیل ذخیره‌شده در پنل وجود ندارد — پاک‌سازی creds و ثبت‌نام مجدد', [
                        'email' => $user->xmplus_client_email,
                        'order_id' => $order->id,
                    ]);
                    $user->forceFill([
                        'xmplus_client_email' => null,
                        'xmplus_client_password' => null,
                    ])->save();

                    return self::doNewPurchase($api, $settings, $user->fresh(), $plan, $order, $pid, $billing, $aff, $panelBase, $shopPaymentAlreadyCollected, false);
                }
                throw new RuntimeException('XMPlus ساخت فاکتور ناموفق: '.json_encode($inv, JSON_UNESCAPED_UNICODE));
            }
            $invid = $inv['invid'] ?? data_get($inv, 'data.invid');
            if (! is_string($invid) || $invid === '') {
                $invid = is_scalar($inv['invid'] ?? null) ? (string) $inv['invid'] : '';
            }
            $invid = trim($invid);
            if ($invid === '') {
                throw new RuntimeException('XMPlus: شناسه فاکتور (invid) در پاسخ invoice/create نیست.');
            }

            $order->forceFill(['xmplus_inv_id' => $invid])->save();
            // اگر همین‌جا MySQL را Paid کنیم، invoice/view زود «Paid» می‌شود و invoice/pay رد می‌شود؛ در XMPlus معمولاً سرویس فقط پس از مسیر واقعی پرداخت/بستن فاکتور ساخته می‌شود (سپس sublink در account/info).
            $deferDbSyncUntilAfterResellerPay = $shopPaymentAlreadyCollected && $autoPayConfigured;
            if (! $deferDbSyncUntilAfterResellerPay) {
                self::trySyncXmplusInvoiceDatabaseRow($settings, $invid, $order, $shopPaymentAlreadyCollected);
            } else {
                $api->log('info', 'XMPlus: همگام‌سازی DB فاکتور به‌تعویق افتاد تا ابتدا invoice/pay با درگاه نمایندگی اجرا شود', [
                    'invid' => $invid,
                    'order_id' => $order->id,
                ]);
            }
        } else {
            $api->log('info', 'XMPlus بدون invoice/create جدید (ادامه با همان فاکتور)', [
                'step' => 'reuse_invoice',
                'invid' => $invid,
                'order_id' => $order->id,
            ]);
        }

        if ($shopPaymentAlreadyCollected) {
            if (! $autoPayConfigured) {
                throw new RuntimeException(
                    'XMPlus: مشتری در فروشگاه شما قبلاً پرداخت کرده؛ نباید دوباره در XMPlus پرداخت کند. '
                    .'در تنظیمات تم، فیلد «شناسه درگاه برای پرداخت خودکار فاکتور» را با شناسهٔ عددی درگاهی پر کنید که فاکتور را با اعتبار/موجودی شما در پنل XMPlus می‌بندد (مثلاً موجودی نمایندگی)، نه درگاه کارت مشتری. '
                    .'منوی انتخاب درگاه در تلگرام فقط برای حالت‌هایی است که هنوز در سایت پرداخت نشده باشد.'
                );
            }
            $alreadyPaid = false;
            $alreadyPaidHasServiceId = false;
            try {
                $viewPre = $api->invoiceView($email, $passwdPlain, $invid);
                $alreadyPaid = self::invoiceViewResponseIsPaid($viewPre);
                $alreadyPaidHasServiceId = self::invoiceViewResponseHasServiceId($viewPre);
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus invoice/view قبل از pay (فروشگاه)', ['error' => $e->getMessage(), 'invid' => $invid]);
            }
            if ($alreadyPaid) {
                if ($alreadyPaidHasServiceId) {
                    $pay = [];
                    $customerCheckout = false;
                    $api->log('info', 'XMPlus فاکتور از قبل Paid (با serviceid) است؛ invoice/pay فراخوانی نشد', ['invid' => $invid, 'order_id' => $order->id]);
                } else {
                    // در برخی پنل‌ها Paid بدون serviceid با یک بار invoice/pay مجدد (درگاه نمایندگی) اصلاح می‌شود.
                    $api->log('warning', 'XMPlus فاکتور از قبل Paid ولی بدون serviceid است؛ تلاش برای invoice/pay مجدد', [
                        'invid' => $invid,
                        'order_id' => $order->id,
                        'gatewayid' => (int) $gatewayId,
                    ]);
                    $pay = self::runInvoicePayStrictForShopCollected(
                        $api,
                        function () use ($api, $email, $passwdPlain, $invid, $gatewayId) {
                            return $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                        },
                        $email,
                        $passwdPlain,
                        $invid
                    );
                    $customerCheckout = self::invoicePayLooksCustomerSideCheckout($pay);
                }
            } else {
                $pay = self::runInvoicePayStrictForShopCollected(
                    $api,
                    function () use ($api, $email, $passwdPlain, $invid, $gatewayId) {
                        return $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                    },
                    $email,
                    $passwdPlain,
                    $invid
                );
                $api->log('info', 'XMPlus invoice/pay (تسویه پس از پرداخت فروشگاه)', [
                    'step' => 'invoice_pay_shop_collected',
                    'invid' => $invid,
                    'gatewayid' => (int) $gatewayId,
                    'response_summary' => array_keys($pay),
                ]);
                $customerCheckout = self::invoicePayLooksCustomerSideCheckout($pay);
            }
            if ($customerCheckout) {
                $api->log('warning', 'XMPlus: invoice/pay برای تسویهٔ فروشگاه، درگاه سمت مشتری برگرداند (مثلاً لینک PayPal). همگام‌سازی MySQL انجام نمی‌شود — آن کار فقط ردیف را Paid می‌کرد بدون ساخت سرویس در پنل.', [
                    'invid' => $invid,
                    'gateway' => $pay['gateway'] ?? null,
                    'gatewayid_setting' => (int) $gatewayId,
                ]);
                throw new RuntimeException(
                    'XMPlus: شناسه درگاه خودکار فاکتور در تنظیمات تم الان '.(int) $gatewayId.' است؛ پاسخ invoice/pay درگاه سمت مشتری است ('.(string) ($pay['gateway'] ?? '?').') '
                    .'و لینک پرداخت خارجی می‌دهد. وقتی مشتری در فروشگاه قبلاً پرداخت کرده، در XMPlus باید درگاهی انتخاب شود که با یک بار API فاکتور واقعاً بسته و سرویس ساخته شود (مثلاً کسر از موجودی/اعتبار نماینده)، نه PayPal یا درگاه مشابه. '
                    .'در Client API لینک اشتراک پس از ساخت سرویس در account/info یا service/info برمی‌گردد؛ اگر فقط ردیف فاکتور Paid شود بدون تکمیل پرداخت در پنل، services خالی می‌ماند. '
                    .'شناسهٔ درگاه درست را از POST /api/client/gateways بگیرید، در Theme Settings ذخیره کنید و دوباره تأیید سفارش را اجرا کنید.'
                );
            }
            if (is_array($pay) && self::invoicePayGatewayIsCard2Card($pay)) {
                $api->log('warning', 'XMPlus: پس از پرداخت فروشگاه، invoice/pay درگاه Card2Card برگرداند — شناسهٔ xmplus_auto_pay_gateway_id اشتباه است؛ همگام‌سازی MySQL انجام نمی‌شود (همان فقط Paid می‌کرد بدون ساخت سرویس).', [
                    'invid' => $invid,
                    'order_id' => $order->id,
                    'gateway_in_response' => $pay['gateway'] ?? null,
                    'gatewayid_setting' => (int) $gatewayId,
                ]);
                throw new RuntimeException(
                    'XMPlus: «شناسه درگاه برای پرداخت خودکار فاکتور» در تنظیمات تم الان '.(int) $gatewayId.' است و پاسخ invoice/pay می‌گوید درگاه **Card2Card** است. '
                    .'برای مشتریانی که در VPNMarket قبلاً پرداخت کرده‌اند باید شناسهٔ درگاه **ShopPrepaidConfirm** (یا موجودی/اعتبار نماینده) را از `POST /api/client/gateways` بگذارید، نه Card2Card. '
                    .'اگر با Card2Card ادامه دهید و ردیف فاکتور را با MySQL Paid کنید، در API فاکتور Paid می‌شود اما **سرویس و sublink ساخته نمی‌شود** (همان وضعی که در لاگ دیدید). '
                    .'شناسه را اصلاح کنید، سپس برای این سفارش در پنل XMPlus فاکتور را در صورت نیاز دستی اصلاح/دوباره بسازید و در فروشگاه دوباره «تأیید و اجرا» بزنید.'
                );
            }
            $async = self::invoicePayLooksAsync($pay);
            $maxAttempts = $async ? 72 : 18;
            $sleepSeconds = $async ? 5 : 2;
            $pollFb = [
                'panel_base' => $panelBase,
                'order_id' => $order->id,
                'shop_collected_card2card' => is_array($pay) && self::invoicePayGatewayIsCard2Card($pay),
                'shop_prepaid_confirm_gateway' => is_array($pay) && self::invoicePayGatewayIsShopPrepaidConfirm($pay),
            ];
            $result = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, null, true, $maxAttempts, $sleepSeconds, false, $pollFb);
            $sublink = $result['sublink'];
            $sid = $result['sid'];

            return [
                'final_config' => $sublink,
                'panel_username' => $email,
                'panel_client_id' => $sid !== null ? (string) $sid : null,
                'credentials_message' => $credentialsMessage,
                'plain_password' => $credentialsMessage ? $passwdPlain : null,
            ];
        }

        if ($autoPayConfigured) {
            $alreadyPaid = false;
            $alreadyPaidHasServiceId = false;
            try {
                $viewPre = $api->invoiceView($email, $passwdPlain, $invid);
                $alreadyPaid = self::invoiceViewResponseIsPaid($viewPre);
                $alreadyPaidHasServiceId = self::invoiceViewResponseHasServiceId($viewPre);
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus invoice/view قبل از pay', ['error' => $e->getMessage(), 'invid' => $invid]);
            }
            $pay = [];
            if (! $alreadyPaid) {
                try {
                    $pay = $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                    $api->log('info', 'XMPlus invoice/pay فراخوانی شد', ['step' => 'invoice_pay', 'response_summary' => is_array($pay) ? array_keys($pay) : 'n/a']);
                } catch (\Throwable $e) {
                    $api->log('warning', 'XMPlus invoice/pay خطا (ادامه با polling)', [
                        'step' => 'invoice_pay_error',
                        'error' => $e->getMessage(),
                    ]);
                }
            } else {
                if ($alreadyPaidHasServiceId) {
                    $api->log('info', 'XMPlus فاکتور از قبل Paid (با serviceid)؛ invoice/pay رد شد', ['invid' => $invid]);
                } else {
                    $api->log('warning', 'XMPlus فاکتور از قبل Paid ولی بدون serviceid؛ تلاش برای invoice/pay مجدد', ['invid' => $invid, 'gatewayid' => (int) $gatewayId]);
                    try {
                        $pay = $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                        $api->log('info', 'XMPlus invoice/pay مجدد برای Paid بدون serviceid فراخوانی شد', ['step' => 'invoice_pay_retry_paid_without_service']);
                    } catch (\Throwable $e) {
                        $api->log('warning', 'XMPlus invoice/pay مجدد برای Paid بدون serviceid خطا (ادامه با polling)', [
                            'step' => 'invoice_pay_retry_paid_without_service_error',
                            'error' => $e->getMessage(),
                            'invid' => $invid,
                        ]);
                    }
                }
            }
            $async = self::invoicePayLooksAsync($pay);
            $maxAttempts = $async ? 72 : 18;
            $sleepSeconds = $async ? 5 : 2;
            $pollFb = ['panel_base' => $panelBase, 'order_id' => $order->id];
            $result = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, null, true, $maxAttempts, $sleepSeconds, false, $pollFb);
            $sublink = $result['sublink'];
            $sid = $result['sid'];

            return [
                'final_config' => $sublink,
                'panel_username' => $email,
                'panel_client_id' => $sid !== null ? (string) $sid : null,
                'credentials_message' => $credentialsMessage,
                'plain_password' => $credentialsMessage ? $passwdPlain : null,
            ];
        }

        if (self::shouldOfferTelegramGatewayPicker($settings, $user)) {
            $gateways = $api->listGateways();
            if ($gateways === []) {
                throw new RuntimeException(
                    'XMPlus: لیست درگاه‌ها از API خالی است. در پنل درگاه فعال کنید یا در تنظیمات فروشگاه «شناسه درگاه پرداخت خودکار فاکتور» را بگذارید.'
                );
            }
            $options = [];
            foreach ($gateways as $g) {
                $options[] = ['id' => $g['id'], 'name' => $g['name']];
            }
            Cache::put(self::invoiceContextCacheKey($order->id), [
                'email' => $email,
                'passwd' => $passwdPlain,
                'invid' => $invid,
                'pid' => $pid,
                'is_renewal' => false,
                'original_order_id' => null,
                'known_sid' => null,
                'gateway_options' => $options,
                'credentials_message' => $credentialsMessage,
                'wallet_already_charged' => $order->status === 'paid',
                'order_id' => $order->id,
                'panel_base' => $panelBase,
            ], now()->addHours(48));

            return [
                'phase' => 'await_gateway',
                'order_id' => $order->id,
                'credentials_message' => $credentialsMessage,
            ];
        }

        throw new RuntimeException(
            'XMPlus: نه «شناسه درگاه پرداخت خودکار» تنظیم شده و نه انتخاب درگاه در تلگرام فعال است، یا کاربر chat_id تلگرام ندارد. یکی از این‌ها را در تنظیمات XMPlus اصلاح کنید.'
        );
    }

    public static function invoiceContextCacheKey(int $orderId): string
    {
        return 'xmplus_inv_ctx:'.$orderId;
    }

    /**
     * اگر در تنظیمات فعال باشد، ردیف invoice در دیتابیس XMPlus را status=1 می‌کند (فقط وقتی پول در VPNMarket تأیید شده).
     */
    protected static function trySyncXmplusInvoiceDatabaseRow(Collection $settings, string $invid, ?Order $order, bool $shopPaymentAlreadyCollected): void
    {
        if (! $shopPaymentAlreadyCollected || ! XmplusInvoiceDatabaseSyncService::enabled($settings)) {
            return;
        }
        try {
            XmplusInvoiceDatabaseSyncService::markInvoicePaid($settings, $invid, $order);
        } catch (\Throwable $e) {
            Log::channel('xmplus')->warning('XMPlus invoice DB sync خطا: '.$e->getMessage(), [
                'invid' => $invid,
                'order_id' => $order?->id,
            ]);
        }
    }

    /**
     * پس از ثبت پرداخت (کیف پول / Plisio و غیره) و قبل از تکمیل درگاه XMPlus، کش را به‌روز کن تا تراکنش دوباره ساخته نشود.
     */
    public static function markInvoiceContextWalletCharged(int $orderId): void
    {
        $key = self::invoiceContextCacheKey($orderId);
        $ctx = Cache::get($key);
        if (! is_array($ctx)) {
            return;
        }
        $ctx['wallet_already_charged'] = true;
        Cache::put($key, $ctx, now()->addHours(48));
    }

    /**
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string, credentials_message: ?string, plain_password: ?string}|array{phase: string, order_id: int, credentials_message: null}
     */
    protected static function doRenewal(
        XmplusService $api,
        Collection $settings,
        User $user,
        Plan $plan,
        Order $renewalOrder,
        ?Order $originalOrder,
        int $pid,
        string $panelBase,
        bool $shopPaymentAlreadyCollected = false
    ): array {
        if (! $originalOrder) {
            throw new InvalidArgumentException('XMPlus تمدید: سفارش اصلی نامعتبر است.');
        }
        $email = $user->xmplus_client_email ?? $originalOrder->panel_username;
        $passwdPlain = $user->xmplus_client_password;
        if (! is_string($email) || $email === '' || ! is_string($passwdPlain) || $passwdPlain === '') {
            throw new RuntimeException('XMPlus تمدید: اطلاعات ورود کاربر به پنل یافت نشد.');
        }

        $sid = $originalOrder->panel_client_id ?? null;
        if ($sid === null || $sid === '') {
            throw new RuntimeException('XMPlus تمدید: شناسه سرویس (sid) روی سفارش اصلی ذخیره نشده؛ یک بار سرویس را دوباره از پنل همگام کنید یا سفارش جدید بگیرید.');
        }
        $sid = (int) $sid;

        $gatewayId = $settings->get('xmplus_auto_pay_gateway_id');
        $autoPayConfigured = $gatewayId !== null && $gatewayId !== '' && is_numeric((string) $gatewayId);

        $invid = trim((string) ($renewalOrder->xmplus_inv_id ?? ''));
        
        // بررسی می‌کنیم که آیا service اصلی هنوز وجود دارد یا خیر
        $serviceExists = false;
        if ($invid !== '') {
            try {
                $svcCheck = $api->serviceInfo($email, $passwdPlain, $sid);
                if (self::apiOk($svcCheck)) {
                    $serviceExists = true;
                }
            } catch (\Throwable $e) {
                $errMsg = strtolower((string) ($e->getMessage() ?? ''));
                if (str_contains($errMsg, 'service not found') || str_contains($errMsg, 'not found')) {
                    $api->log('warning', 'XMPlus تمدید: service اصلی یافت نشد؛ فاکتور قبلی نامعتبر شد', [
                        'sid' => $sid,
                        'old_invid' => $invid,
                        'order_id' => $renewalOrder->id,
                    ]);
                    // فاکتور قبلی را پاک می‌کنیم تا یک فاکتور جدید بسازیم
                    $invid = '';
                    $renewalOrder->forceFill(['xmplus_inv_id' => null])->save();
                } else {
                    $api->log('debug', 'XMPlus تمدید: خطا در بررسی service', ['error' => $e->getMessage(), 'sid' => $sid]);
                }
            }
        }
        
        if ($invid === '') {
            $renew = $api->serviceRenew($email, $passwdPlain, $sid);
            if (! self::apiOk($renew)) {
                throw new RuntimeException('XMPlus تمدید ناموفق: '.json_encode($renew, JSON_UNESCAPED_UNICODE));
            }
            $invid = $renew['invid'] ?? null;
            $invid = is_scalar($invid) ? trim((string) $invid) : '';

            if ($invid !== '') {
                $renewalOrder->forceFill(['xmplus_inv_id' => $invid])->save();
                
                // ✅ تنظیم serviceid در invoice تمدید
                try {
                    XmplusInvoiceDatabaseSyncService::setRenewalInvoiceServiceId($settings, $invid, $sid);
                } catch (\Throwable $e) {
                    $api->log('warning', 'XMPlus تمدید: خطا در set کردن serviceid در invoice', [
                        'error' => $e->getMessage(),
                        'invid' => $invid,
                        'sid' => $sid,
                    ]);
                }
                
                $deferRenewalDbSync = $shopPaymentAlreadyCollected && $autoPayConfigured;
                if (! $deferRenewalDbSync) {
                    self::trySyncXmplusInvoiceDatabaseRow($settings, $invid, $renewalOrder, $shopPaymentAlreadyCollected);
                } else {
                    $api->log('info', 'XMPlus تمدید: همگام‌سازی DB فاکتور به‌تعویق افتاد تا ابتدا invoice/pay اجرا شود', [
                        'invid' => $invid,
                        'order_id' => $renewalOrder->id,
                    ]);
                }
            }
        } else {
            $api->log('info', 'XMPlus تمدید بدون serviceRenew جدید (همان فاکتور)', [
                'invid' => $invid,
                'order_id' => $renewalOrder->id,
                'service_exists' => $serviceExists,
            ]);
            
            // ✅ اطمینان از اینکه serviceid در invoice موجود است
            try {
                XmplusInvoiceDatabaseSyncService::setRenewalInvoiceServiceId($settings, $invid, $sid);
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus تمدید: خطا در set کردن serviceid در invoice موجود', [
                    'error' => $e->getMessage(),
                    'invid' => $invid,
                    'sid' => $sid,
                ]);
            }
        }

        if ($shopPaymentAlreadyCollected && $invid !== '' && ! $autoPayConfigured) {
            throw new RuntimeException(
                'XMPlus تمدید: مشتری در فروشگاه پرداخت کرده؛ برای بستن فاکتور تمدید در XMPlus باید «شناسه درگاه پرداخت خودکار فاکتور» (تسویه با اعتبار فروشنده) را در تنظیمات تم پر کنید.'
            );
        }

        if ($invid !== '' && $autoPayConfigured) {
            $alreadyPaid = false;
            try {
                $viewPre = $api->invoiceView($email, $passwdPlain, $invid);
                $alreadyPaid = self::invoiceViewResponseIsPaid($viewPre);
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus تمدید: invoice/view قبل از pay', ['error' => $e->getMessage(), 'invid' => $invid]);
            }
            if ($alreadyPaid) {
                $pay = [];
                $api->log('info', 'XMPlus تمدید: فاکتور از قبل Paid؛ invoice/pay رد شد', ['invid' => $invid]);
            } elseif ($shopPaymentAlreadyCollected) {
                $pay = self::runInvoicePayStrictForShopCollected(
                    $api,
                    function () use ($api, $email, $passwdPlain, $invid, $gatewayId) {
                        return $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                    },
                    $email,
                    $passwdPlain,
                    $invid
                );
                if (is_array($pay) && self::invoicePayLooksCustomerSideCheckout($pay)) {
                    $api->log('warning', 'XMPlus تمدید: invoice/pay درگاه سمت مشتری؛ همگام‌سازی MySQL انجام نمی‌شود.', [
                        'invid' => $invid,
                        'gateway' => $pay['gateway'] ?? null,
                        'gatewayid_setting' => (int) $gatewayId,
                    ]);
                    throw new RuntimeException(
                        'XMPlus تمدید: شناسه درگاه خودکار ('.(int) $gatewayId.') درگاه سمت مشتری است ('.(string) ($pay['gateway'] ?? '?').'). '
                        .'برای تسویه پس از پرداخت فروشگاه باید در Theme Settings درگاه موجودی/اعتبار نماینده در XMPlus را بگذارید، نه PayPal یا لینک پرداخت خارجی. '
                        .'سپس دوباره تأیید تمدید را اجرا کنید.'
                    );
                }
                if (is_array($pay) && self::invoicePayGatewayIsCard2Card($pay)) {
                    $api->log('warning', 'XMPlus تمدید: invoice/pay Card2Card پس از پرداخت فروشگاه — شناسهٔ درگاه خودکار اشتباه است؛ همگام‌سازی MySQL انجام نمی‌شود.', [
                        'invid' => $invid,
                        'gatewayid_setting' => (int) $gatewayId,
                        'gateway' => $pay['gateway'] ?? null,
                    ]);
                    throw new RuntimeException(
                        'XMPlus تمدید: شناسه درگاه خودکار ('.(int) $gatewayId.') به Card2Card اشاره می‌کند. برای تسویه پس از پرداخت فروشگاه درگاه ShopPrepaidConfirm یا موجودی نماینده را در Theme Settings بگذارید، نه Card2Card. '
                        .'همگام‌سازی MySQL فاکتور را Paid می‌کند بدون ساخت سرویس.'
                    );
                }
            } else {
                $pay = [];
                try {
                    $pay = $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                } catch (\Throwable $e) {
                    $api->log('warning', 'XMPlus تمدید: invoice/pay خطا', ['error' => $e->getMessage()]);
                }
            }
            $async = is_array($pay) && self::invoicePayLooksAsync($pay);
            $maxAttempts = $async ? 72 : 18;
            $sleepSeconds = $async ? 5 : 2;
            $pollFb = [
                'panel_base' => $panelBase,
                'order_id' => $renewalOrder->id,
                'shop_collected_card2card' => $shopPaymentAlreadyCollected && is_array($pay) && self::invoicePayGatewayIsCard2Card($pay),
                'shop_prepaid_confirm_gateway' => $shopPaymentAlreadyCollected && is_array($pay) && self::invoicePayGatewayIsShopPrepaidConfirm($pay),
            ];
            $poll = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, $sid, true, $maxAttempts, $sleepSeconds, false, $pollFb);
            $sublink = $poll['sublink'];
            $outSid = $poll['sid'] ?? $sid;

            // ✅ تمدید service بر اساس package تعریف شده در XMPlus
            if ($shopPaymentAlreadyCollected) {
                try {
                    $invoiceViewForRenewal = $api->invoiceView($email, $passwdPlain, $invid);
                    
                    if (self::invoiceViewResponseIsPaid($invoiceViewForRenewal)) {
                        $renewed = XmplusPackageAwareRenewalService::renewServiceFromPackage(
                            $api,
                            $email,
                            $passwdPlain,
                            $sid,
                            $pid,
                            $settings
                        );
                        
                        if ($renewed) {
                            $api->log('info', 'XMPlus تمدید: ✅ service با package تمدید شد', [
                                'invid' => $invid,
                                'service_id' => $sid,
                                'package_id' => $pid,
                            ]);
                        }
                    }
                } catch (\Throwable $e) {
                    $api->log('warning', 'XMPlus تمدید: خطا در تمدید با package', [
                        'error' => $e->getMessage(),
                        'invid' => $invid,
                        'sid' => $sid,
                        'pid' => $pid,
                    ]);
                }
            }

            return [
                'final_config' => $sublink,
                'panel_username' => $email,
                'panel_client_id' => (string) $outSid,
                'credentials_message' => null,
                'plain_password' => null,
            ];
        }

        if ($invid !== '' && self::shouldOfferTelegramGatewayPicker($settings, $user)) {
            $gateways = $api->listGateways();
            if ($gateways === []) {
                throw new RuntimeException(
                    'XMPlus تمدید: لیست درگاه‌ها خالی است؛ درگاه خودکار یا درگاه در پنل را تنظیم کنید.'
                );
            }
            $options = [];
            foreach ($gateways as $g) {
                $options[] = ['id' => $g['id'], 'name' => $g['name']];
            }
            Cache::put(self::invoiceContextCacheKey($renewalOrder->id), [
                'email' => $email,
                'passwd' => $passwdPlain,
                'invid' => $invid,
                'pid' => $pid,
                'is_renewal' => true,
                'original_order_id' => $originalOrder->id,
                'known_sid' => $sid,
                'gateway_options' => $options,
                'credentials_message' => null,
                'wallet_already_charged' => $renewalOrder->status === 'paid',
                'order_id' => $renewalOrder->id,
                'panel_base' => $panelBase,
            ], now()->addHours(48));

            return [
                'phase' => 'await_gateway',
                'order_id' => $renewalOrder->id,
                'credentials_message' => null,
            ];
        }

        if ($invid !== '') {
            $pollFb = ['panel_base' => $panelBase, 'order_id' => $renewalOrder->id];
            $poll = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, $sid, false, 18, 2, false, $pollFb);
            $sublink = $poll['sublink'];
            $outSid = $poll['sid'] ?? $sid;

            return [
                'final_config' => $sublink,
                'panel_username' => $email,
                'panel_client_id' => (string) $outSid,
                'credentials_message' => null,
                'plain_password' => null,
            ];
        } else {
            $info = $api->serviceInfo($email, $passwdPlain, $sid);
            $sublink = self::sublinkFromServiceInfo($info);
            if ($sublink === null) {
                $acc = self::extractAccountPayload($api->accountInfo($email, $passwdPlain));
                $sublink = self::pickSublinkFromServices($acc['services'] ?? [], $pid, $sid);
            }
            $outSid = $sid;
            if ($sublink === null) {
                throw new RuntimeException('XMPlus تمدید: لینک اشتراک بعد از renew دریافت نشد.');
            }
        }

        return [
            'final_config' => $sublink,
            'panel_username' => $email,
            'panel_client_id' => (string) $outSid,
            'credentials_message' => null,
            'plain_password' => null,
        ];
    }

    protected static function xmplusDisplayName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        $name = preg_replace('/[^\p{L}\p{N}\s\-_\.]/u', '', $name) ?? '';
        $name = trim($name);

        return $name !== '' ? Str::limit($name, 40, '') : 'tg'.$user->id;
    }

    protected static function formatCredentialsMessage(string $email, string $password, string $panelBase): string
    {
        return "👤 ورود پنل XMPlus:\nایمیل: {$email}\nرمز: {$password}\n🌐 {$panelBase}";
    }

    /**
     * وقتی فاکتور در API Paid است اما هنوز sublink/servicelist برنمی‌گردد (مشکل یا تأخیر پنل).
     *
     * @param  array{panel_base: string, order_id?: int, shop_collected_card2card?: bool, shop_prepaid_confirm_gateway?: bool}  $ctx
     */
    protected static function formatPaidInvoiceWithoutSublinkUserNotice(string $email, string $invid, array $ctx): string
    {
        $panel = rtrim((string) ($ctx['panel_base'] ?? ''), '/');
        $orderId = (int) ($ctx['order_id'] ?? 0);
        $card2cardDb = ! empty($ctx['shop_collected_card2card']);
        $shopPrepaid = ! empty($ctx['shop_prepaid_confirm_gateway']);
        $lines = [
            '⚠️ فاکتور در XMPlus با وضعیت Paid ثبت شده، اما API هنوز لینک اشتراک (subscription) را برنمی‌گرداند.',
            '',
        ];
        if ($shopPrepaid) {
            $lines[] = 'درگاه **ShopPrepaidConfirm** روی پنل باید علاوه بر Paid کردن فاکتور، همان منطقی را اجرا کند که با تأیید پرداخت در ادمین سرویس و sublink ساخته می‌شود. اگر فقط فیلدهای status/paid_date در دیتابیس عوض شوند، در Client API معمولاً `account/info` با `services: []` و فاکتور با `serviceid` خالی می‌ماند.';
            $lines[] = '';
            $lines[] = 'روی سرور XMPlus آخرین `ShopPrepaidConfirm.php` را بگذارید؛ `tryFulfillSubscriptionAfterPaid()` و `tryAppContainerFulfillHooks()` سرویس‌های رایج را امتحان می‌کنند. اگر باز هم لینک نیامد، در سورس XMPlus همان کلاس/متدی را که ادمین با آن فاکتور را تأیید و سرویس می‌سازد پیدا کنید و در انتهای `ShopPrepaidConfirmKernel::settle()` صریحاً صدا بزنید.';
            $lines[] = '';
        }
        if ($card2cardDb) {
            $lines[] = 'این حالت اغلب وقتی رخ می‌دهد که با درگاه Card2Card فقط invoice/pay زده شده و سپس ردیف فاکتور در MySQL به Paid به‌روز شده باشد؛ در بسیاری از نسخه‌های XMPlus **همان به‌روزرسانی DB منطق ساخت سرویس را اجرا نمی‌کند** و account/services خالی می‌ماند.';
            $lines[] = '';
            $lines[] = 'راه‌حل پایدار: در Theme Settings به‌جای Card2Card از درگاهی استفاده کنید که با **موجودی/اعتبار نماینده** فاکتور را از مسیر API واقعاً ببندد تا پنل سرویس و sublink بسازد؛ یا سرویس را از ادمین XMPlus برای این کاربر دستی ایجاد کنید.';
            $lines[] = '';
        }
        $lines[] = 'احتمالاً سرویس در پنل هنوز ساخته نشده، نیاز به تأیید دستی دارد، یا نسخهٔ پنل با Client API هم‌خوان نیست.';
        $lines[] = '';
        $lines[] = 'اگر فاکتور فقط با ویرایش مستقیم دیتابیس به Paid رسیده (بدون تکمیل مسیر پرداخت داخل پنل)، XMPlus معمولاً سرویس و لینک نمی‌سازد؛ باید از مسیر پنل یا invoice/pay با درگاه نمایندگی تسویه شود.';
        $lines[] = '';
        if ($panel !== '') {
            $lines[] = '🌐 ورود به پنل کاربری: '.$panel;
        } else {
            $lines[] = 'برای نمایش لینک مستقیم ورود، «آدرس پایه پنل XMPlus» (xmplus_panel_url) را در تنظیمات قالب بگذارید.';
        }
        $lines[] = '📧 ایمیل پنل: '.$email;
        $lines[] = '📄 شناسه فاکتور (invid): '.$invid;
        if ($orderId > 0) {
            $lines[] = '🛒 شماره سفارش فروشگاه: #'.$orderId;
        }
        $lines[] = '';
        $lines[] = 'لطفاً از بخش «سرویس‌ها / اشتراک» همان پنل لینک را بردارید. اگر سرویسی دیده نمی‌شود با پشتیبانی هاست XMPlus تماس بگیرید.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function apiOk(array $row): bool
    {
        if (! empty($row['invid'])) {
            return true;
        }
        $st = strtolower((string) ($row['status'] ?? ''));
        $code = $row['code'] ?? null;
        if ($code !== null && (int) $code !== 100) {
            return false;
        }

        return in_array($st, ['success', 'sucess', 'active'], true);
    }

    /**
     * فقط وقتی true که پاسخ API واقعاً یعنی «این ایمیل/حساب در پنل Client دیگر وجود ندارد».
     *
     * نکته: XMPlus برای کد ۲۰۸ چند خطای متفاوت می‌دهد (billing unavailable، already registered، invalid password، …).
     * اگر هر ۲۰۸ را «حساب نیست» فرض کنیم، بعد از register موفق و شکست invoice/create، creds کاربر پاک می‌شود — دقیقاً باگ گزارش‌شده.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function apiIsEmailNotFoundOnClient(array $row): bool
    {
        $msg = strtolower((string) ($row['message'] ?? ''));
        $code = (int) ($row['code'] ?? 0);

        if ($code === 208) {
            if (self::apiIsEmailAlreadyRegistered($row)) {
                return false;
            }
            if (str_contains($msg, 'billing') && str_contains($msg, 'unavailable')) {
                return false;
            }
            if (
                str_contains($msg, 'password')
                && (str_contains($msg, 'invalid') || str_contains($msg, 'incorrect') || str_contains($msg, 'wrong'))
            ) {
                return false;
            }
            foreach ([
                'does not exist', 'not found', 'could not be found', 'no such user',
                'user not found', 'account not found', 'email not found',
            ] as $needle) {
                if (str_contains($msg, $needle)) {
                    return true;
                }
            }

            return false;
        }

        $st = strtolower((string) ($row['status'] ?? ''));
        if ($st === 'error' && stripos((string) ($row['message'] ?? ''), 'does not exist') !== false) {
            return true;
        }

        return false;
    }

    /**
     * اگر billing انتخاب‌شده در پنل غیرفعال باشد، XMPlus «billing unavailable» می‌دهد؛ چند سیکل متداول را امتحان می‌کنیم.
     *
     * @return array<string, mixed>
     */
    protected static function invoiceCreateWithBillingFallback(
        XmplusService $api,
        string $email,
        string $passwd,
        int $pid,
        string $preferredBilling,
        int $orderIdForLog = 0
    ): array {
        $preferredBilling = self::canonicalXmplusBillingForApi($preferredBilling);
        $candidates = self::resolveInvoiceCreateBillingCandidates($api, $pid, $preferredBilling);
        $last = ['status' => 'error', 'code' => 0, 'message' => 'invoice create: no attempt'];
        foreach ($candidates as $b) {
            $b = self::canonicalXmplusBillingForApi((string) $b);
            $inv = $api->invoiceCreate($email, $passwd, $pid, $b, '', 1);
            $last = $inv;
            if (self::apiOk($inv)) {
                if ($b !== $preferredBilling) {
                    $api->log('info', 'XMPlus invoice/create: از billing جایگزین استفاده شد', [
                        'preferred' => $preferredBilling,
                        'used' => $b,
                        'pid' => $pid,
                        'order_id' => $orderIdForLog,
                    ]);
                }

                return $inv;
            }
            $msg = strtolower((string) ($inv['message'] ?? ''));
            $onlyBillingUnavailable = str_contains($msg, 'billing') && str_contains($msg, 'unavailable');
            if (! $onlyBillingUnavailable) {
                break;
            }
        }

        return $last;
    }

    /**
     * قبل از invoice/create نوع بسته و billingهای فعال را از package/info می‌خوانیم تا pid اشتباه (فقط ترافیک) زود مشخص شود
     * و ترتیب امتحان billing با همان چیزی که در پنل روشن است هم‌خوان باشد.
     *
     * @return list<string>
     */
    protected static function resolveInvoiceCreateBillingCandidates(XmplusService $api, int $pid, string $preferred): array
    {
        $preferred = self::canonicalXmplusBillingForApi($preferred);
        // هرگز «quarter» نفرستید: در بسیاری از نسخه‌های XMPlus match() فقط «quater» (املای API) را دارد و quarter → HTTP 500 UnhandledMatchError
        $defaults = [$preferred, 'month', 'quater', 'semiannual', 'annual'];
        $defaults = array_values(array_unique(array_filter($defaults, fn ($b) => is_string($b) && $b !== '')));
        try {
            $pinfo = $api->packageInfo($pid);
            $pkg = $pinfo['package'] ?? null;
            if (! is_array($pkg)) {
                return $defaults;
            }
            self::assertPackageSuitableForNewServiceInvoice($pkg, $pid);
            $billing = $pkg['billing'] ?? null;
            if (! is_array($billing)) {
                return $defaults;
            }
            $enabled = [];
            foreach (['month', 'quater', 'semiannual', 'annual'] as $key) {
                $cell = $billing[$key] ?? null;
                if (is_array($cell) && self::xmplusBillingCellLooksEnabled($cell)) {
                    $enabled[] = $key;
                }
            }
            if ($enabled === []) {
                return $defaults;
            }
            $out = [];
            if (in_array($preferred, $enabled, true)) {
                $out[] = $preferred;
            }
            foreach ($enabled as $e) {
                if (! in_array($e, $out, true)) {
                    $out[] = $e;
                }
            }
            foreach ($defaults as $d) {
                if (! in_array($d, $out, true)) {
                    $out[] = $d;
                }
            }

            return $out;
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $api->log('info', 'XMPlus package/info برای ترتیب billing در دسترس نبود', [
                'pid' => $pid,
                'message' => $e->getMessage(),
            ]);

            return $defaults;
        }
    }

    /**
     * املای رسمی Client API برای سه‌ماهه «quater» است؛ «quarter» یا «Quarter» روی بعضی پنل‌ها → HTTP 500 (UnhandledMatchError).
     * بقیهٔ کلیدها را lowercase می‌کنیم تا با match پنل هم‌خوان باشد.
     */
    protected static function canonicalXmplusBillingForApi(string $billing): string
    {
        $trim = trim($billing);
        if ($trim === '') {
            return '';
        }
        $b = strtolower($trim);

        return $b === 'quarter' ? 'quater' : $b;
    }

    /**
     * @param  array<string, mixed>  $cell
     */
    protected static function xmplusBillingCellLooksEnabled(array $cell): bool
    {
        if (strtolower((string) ($cell['status'] ?? '')) === 'on') {
            return true;
        }
        if (isset($cell['price']) && $cell['price'] !== '' && $cell['price'] !== null) {
            return true;
        }
        if (isset($cell['days']) && is_numeric($cell['days']) && (int) $cell['days'] > 0) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $pkg
     */
    protected static function assertPackageSuitableForNewServiceInvoice(array $pkg, int $pid): void
    {
        $type = strtolower((string) ($pkg['type'] ?? ''));
        if ($type !== '' && str_contains($type, 'traffic') && ! str_contains($type, 'full')) {
            throw new InvalidArgumentException(
                'پلن VPNMarket به XMPlus pid='.$pid.' اشاره می‌کند که نوع «ترافیک / Top-up» است، نه «Full Package». '
                .'برای خرید اول باید pid یک بستهٔ کامل از پنل XMPlus بگذارید (بسته‌هایی با دورهٔ month یا quater؛ نه بستهٔ «فقط افزودن حجم» برای کاربر دارای سرویس).'
            );
        }
        $billing = $pkg['billing'] ?? null;
        if (! is_array($billing)) {
            return;
        }
        $fullOn = false;
        foreach (['month', 'quater', 'semiannual', 'annual'] as $key) {
            $cell = $billing[$key] ?? null;
            if (is_array($cell) && self::xmplusBillingCellLooksEnabled($cell)) {
                $fullOn = true;
                break;
            }
        }
        $topCell = $billing['topup_traffic'] ?? null;
        $topOn = is_array($topCell) && self::xmplusBillingCellLooksEnabled($topCell);
        if ($topOn && ! $fullOn) {
            throw new InvalidArgumentException(
                'XMPlus pid='.$pid.' فقط «topup_traffic» دارد و دورهٔ month/quater/… برای ساخت سرویس جدید در پنل روشن نیست؛ '
                .'این بسته برای افزودن ترافیک به سرویس موجود است. در ادمین VPNMarket pid را با یک Full Package عوض کنید.'
            );
        }
    }

    /**
     * ثبت‌نام Client API وقتی همان ایمیل از قبل در XMPlus وجود دارد (VPNMarket هنوز creds ذخیره نکرده).
     *
     * @param  array<string, mixed>  $row
     */
    protected static function apiIsEmailAlreadyRegistered(array $row): bool
    {
        $msg = strtolower((string) ($row['message'] ?? ''));

        return str_contains($msg, 'already registered') || str_contains($msg, 'already been registered');
    }

    /**
     * @return array{sublink: string, sid: ?int}
     */
    protected static function pollForSublink(
        XmplusService $api,
        string $email,
        string $passwd,
        string $invid,
        int $expectPid,
        ?int $knownSid = null,
        bool $autoPayGatewayConfigured = false,
        int $maxAttempts = 18,
        int $sleepSeconds = 2,
        bool $shopCollectedCustomerGateway = false,
        ?array $paidWithoutSublinkFallbackContext = null
    ): array {
        $lastStatus = null;
        $inv = [];
        $attemptLimit = $maxAttempts;
        $earlyPaidNoServiceAfter = self::POLL_EARLY_FALLBACK_WHEN_PAID_NO_SERVICES_AFTER;
        if (
            is_array($paidWithoutSublinkFallbackContext)
            && ! empty($paidWithoutSublinkFallbackContext['shop_collected_card2card'])
        ) {
            $earlyPaidNoServiceAfter = min(
                $earlyPaidNoServiceAfter,
                self::POLL_EARLY_FALLBACK_CARD2CARD_DB_SYNC_PAID_NO_SERVICE_AFTER
            );
        }
        if (
            is_array($paidWithoutSublinkFallbackContext)
            && ! empty($paidWithoutSublinkFallbackContext['shop_prepaid_confirm_gateway'])
        ) {
            $earlyPaidNoServiceAfter = min(
                $earlyPaidNoServiceAfter,
                self::POLL_EARLY_FALLBACK_SHOP_PREPAID_PAID_NO_SERVICE_AFTER
            );
        }

        for ($i = 0; $i < $attemptLimit; $i++) {
            $inv = [];
            $view = null;
            try {
                $view = $api->invoiceView($email, $passwd, $invid);
                if (is_array($view) && self::apiIsEmailNotFoundOnClient($view)) {
                    throw new RuntimeException(
                        'XMPlus polling متوقف شد: '.trim((string) ($view['message'] ?? 'خطای API')).' (کد '.(string) ($view['code'] ?? '?').'). '.
                        'اگر حساب در پنل حذف شده، در VPNMarket برای این کاربر ایمیل/رمز XMPlus را پاک کنید و سفارش را دوباره تأیید کنید.'
                    );
                }
                $inv = is_array($view['invoice'] ?? null) ? $view['invoice'] : [];
                $lastStatus = $inv['status'] ?? null;
            } catch (RuntimeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus poll invoice_view خطا', ['attempt' => $i + 1, 'error' => $e->getMessage()]);
                $lastStatus = null;
            }

            if ($i === 0 && ! $autoPayGatewayConfigured && $lastStatus === 'Pending') {
                throw new RuntimeException(
                    'XMPlus: فاکتور ساخته شد اما وضعیت آن Pending است و «شناسه درگاه برای پرداخت خودکار فاکتور» در تنظیمات XMPlus خالی است. '.
                    'بدون invoice/pay فاکتور پرداخت نمی‌شود و سرویس فعال نمی‌شود. در پنل XMPlus شناسه عددی درگاهی که از Client API پرداخت را می‌پذیرد را در Theme Settings وارد کنید، یا فاکتور را در پنل دستی پرداخت کنید و بعد سفارش را دوباره تأیید کنید.'
                );
            }

            if (
                $lastStatus === 'Pending'
                && is_array($paidWithoutSublinkFallbackContext)
                && ! empty($paidWithoutSublinkFallbackContext['shop_collected_card2card'])
                && $autoPayGatewayConfigured
                && ($i + 1) >= 6
            ) {
                throw new RuntimeException(
                    'XMPlus: فاکتور «'.$invid.'» با درگاه Card2Card هنوز Pending است. این درگاه تا وقتی در **پنل مدیریت XMPlus** همان فاکتور را تأیید/تسویه نکنید، Paid نمی‌شود. '.
                    'چون پول در فروشگاه قبلاً گرفته شده، یا همان فاکتور را در ادمین XMPlus ببندید، یا در تنظیمات تم «همگام‌سازی MySQL فاکتور XMPlus» را فعال و درست کنید تا پس از invoice/pay ردیف فاکتور به Paid برسد، یا از درگاه **موجودی/اعتبار نماینده** استفاده کنید که بدون این مرحله فاکتور بسته شود. '.
                    'بعد از Paid شدن در پنل، در VPNMarket دوباره «تأیید و اجرا» بزنید.'
                );
            }

            if (
                $lastStatus === 'Pending'
                && $autoPayGatewayConfigured
                && ! $shopCollectedCustomerGateway
                && ($i + 1) >= 10
            ) {
                throw new RuntimeException(
                    \App\Models\BotMessage::get(
                        'msg_payment_still_pending',
                        '⏳ پرداخت این سفارش هنوز در BypassNET نهایی نشده است (فاکتور «{invoice_id}» همچنان Pending است).\n\nاین معمولاً به این دلیل است که پرداخت در درگاه (PayPal، Stripe و...) هنوز تکمیل نشده یا callback به پنل ارسال نشده است.\n\n▫️ اگر پرداخت را در درگاه تکمیل کردید، لطفاً چند دقیقه صبر کنید و سپس دکمهٔ «✅ پرداخت کردم، بررسی کن» را دوباره بزنید.\n▫️ اگر پرداخت را تکمیل نکردید، به لینک پرداخت برگردید و آن را تمام کنید.',
                        ['invoice_id' => $invid]
                    )
                );
            }

            $accRaw = $api->accountInfo($email, $passwd);
            if (self::apiIsEmailNotFoundOnClient($accRaw)) {
                throw new RuntimeException(
                    'XMPlus account/info در polling خطا داد: '.trim((string) ($accRaw['message'] ?? '')).' — احتمالاً ایمیل/رمز با پنل هم‌خوان نیست یا حساب حذف شده است.'
                );
            }
            $accPayload = self::extractAccountPayload($accRaw);
            $services = $accPayload['services'] ?? [];

            $paidNow = is_string($lastStatus) && strcasecmp($lastStatus, 'Paid') === 0;
            // نباید از $i در سقف استفاده کرد — هر بار attemptLimit بالا می‌رفت و حلقه تقریباً بی‌پایان می‌شد.
            if ($paidNow && $services === []) {
                $attemptLimit = max($attemptLimit, 45);
            }

            if ($paidNow) {
                $sublink = self::pickSublinkFromServices($services, $expectPid, $knownSid);
                if ($sublink !== null) {
                    $sid = self::pickSidFromServices($services, $expectPid, $knownSid);

                    return ['sublink' => $sublink, 'sid' => $sid];
                }
            }

            if ($paidNow) {
                $relaxed = self::pickSublinkFromServicesRelaxed($services, $expectPid);
                if ($relaxed['sublink'] !== null && $relaxed['sublink'] !== '') {
                    return ['sublink' => $relaxed['sublink'], 'sid' => $relaxed['sid']];
                }

                $sidFromInv = self::serviceIdFromInvoiceOrListRow($inv);
                if ($sidFromInv !== null && $sidFromInv > 0) {
                    try {
                        $s = $api->serviceInfo($email, $passwd, $sidFromInv);
                        $sl = self::sublinkFromServiceInfo($s);
                        if ($sl !== null) {
                            return ['sublink' => $sl, 'sid' => $sidFromInv];
                        }
                    } catch (\Throwable $e) {
                        $errMsg = strtolower((string) ($e->getMessage() ?? ''));
                        if (str_contains($errMsg, 'service not found') || str_contains($errMsg, 'not found')) {
                            $api->log('warning', 'XMPlus: سرویس با sid از فاکتور در پنل یافت نشد (احتمالاً حذف شده)', [
                                'error' => $e->getMessage(),
                                'sid' => $sidFromInv,
                                'invid' => $invid,
                                'attempt' => $i + 1,
                            ]);
                            if (($i + 1) >= 5) {
                                throw new RuntimeException(
                                    \App\Models\BotMessage::get(
                                        'err_service_not_found',
                                        "❌ فاکتور «{invoice_id}» با وضعیت Paid است و serviceid={service_id} دارد، اما سرویس در پنل BypassNET یافت نشد.\n\nاین معمولاً به یکی از دلایل زیر است:\n▫️ سرویس از پنل BypassNET حذف شده است\n▫️ سرویس متعلق به کاربر دیگری است (userid mismatch)\n▫️ مشکلی در API پنل BypassNET وجود دارد\n\n🔧 راه‌حل:\n1. وارد پنل BypassNET شوید و بخش «سرویس‌ها» را چک کنید\n2. سرویس با sid={service_id} را جستجو کنید\n3. اگر سرویس وجود دارد، لینک را دستی کپی کنید\n4. اگر سرویس حذف شده، با پشتیبانی BypassNET تماس بگیرید\n\n🌐 ورود به پنل: {panel_url}\n📧 ایمیل: {email}",
                                        [
                                            'invoice_id' => $invid,
                                            'service_id' => $sidFromInv,
                                            'panel_url' => $paidWithoutSublinkFallbackContext['panel_base'] ?? 'https://www.symmetricnet.com',
                                            'email' => $email,
                                        ]
                                    )
                                );
                            }
                        } else {
                            $api->log('debug', 'XMPlus poll service_info (sid از فاکتور)', ['error' => $e->getMessage(), 'sid' => $sidFromInv]);
                        }
                    }
                }

                if ($services === [] || $i % 3 === 2) {
                    try {
                        $list = $api->listInvoices($email, $passwd);
                        foreach ($list['invoices'] ?? [] as $row) {
                            if (! is_array($row)) {
                                continue;
                            }
                            $rowInv = (string) ($row['invioce_id'] ?? $row['invoice_id'] ?? '');
                            if ($rowInv !== $invid) {
                                continue;
                            }
                            $sidList = self::serviceIdFromInvoiceOrListRow($row);
                            if ($sidList !== null && $sidList > 0) {
                                try {
                                    $s = $api->serviceInfo($email, $passwd, $sidList);
                                    $sl = self::sublinkFromServiceInfo($s);
                                    if ($sl !== null) {
                                        return ['sublink' => $sl, 'sid' => $sidList];
                                    }
                                } catch (\Throwable $e) {
                                    $api->log('debug', 'XMPlus poll service_info (sid از invoices)', ['error' => $e->getMessage(), 'sid' => $sidList]);
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $api->log('debug', 'XMPlus poll invoices_list', ['error' => $e->getMessage()]);
                    }
                }
            }

            if (
                $paidNow
                && $services === []
                && is_array($paidWithoutSublinkFallbackContext)
                && ($i + 1) >= $earlyPaidNoServiceAfter
            ) {
                $notice = self::formatPaidInvoiceWithoutSublinkUserNotice($email, $invid, $paidWithoutSublinkFallbackContext);
                $api->log('warning', 'XMPlus: پایان زودهنگام polling — Paid بدون سرویس در API', [
                    'step' => 'poll',
                    'attempt' => $i + 1,
                    'invid' => $invid,
                    'early_after' => $earlyPaidNoServiceAfter,
                ]);

                throw new RuntimeException(
                    $notice."\n\n"
                    .'❗️سفارش در VPNMarket در وضعیت pending نگه داشته شد تا اشتباهاً Paid بدون سرویس ثبت نشود. '
                    .'بعد از رفع منطق ساخت سرویس در پنل XMPlus دوباره «تأیید و اجرا» را بزنید.'
                );
            }

            if ($lastStatus === 'Paid' && $knownSid !== null) {
                try {
                    $s = $api->serviceInfo($email, $passwd, $knownSid);
                    $sl = self::sublinkFromServiceInfo($s);
                    if ($sl !== null) {
                        return ['sublink' => $sl, 'sid' => $knownSid];
                    }
                } catch (\Throwable $e) {
                    $api->log('debug', 'XMPlus poll service_info', ['error' => $e->getMessage()]);
                }
            }

            $api->log('info', 'XMPlus در انتظار فعال‌شدن سرویس', [
                'step' => 'poll',
                'attempt' => $i + 1,
                'invoice_status' => $lastStatus,
            ]);
            sleep($sleepSeconds);
        }

        $statusLabel = (string) ($lastStatus ?? 'نامشخص');
        $totalWait = $attemptLimit * $sleepSeconds;
        if ($statusLabel === 'Pending' && $autoPayGatewayConfigured && $shopCollectedCustomerGateway) {
            throw new RuntimeException(
                'XMPlus: پول این سفارش در فروشگاه گرفته شده، اما «شناسه درگاه پرداخت خودکار» فعلی درگاه سمت مشتری است: API لینک پرداخت دوم می‌دهد و فاکتور در XMPlus Pending می‌ماند. '.
                'در Theme Settings شناسهٔ عددی درگاهی را بگذارید که با موجودی یا اعتبار نماینده در XMPlus فاکتور را از طریق API آنی ببندد؛ سپس «تایید و اجرا» را دوباره بزنید.'
            );
        }
        if ($statusLabel === 'Pending' && $autoPayGatewayConfigured) {
            throw new RuntimeException(
                'XMPlus: فاکتور «'.$invid.'» بعد از invoice/pay هنوز Pending مانده ('.$totalWait.' ثانیه انتظار). '.
                'درگاه «پرداخت خودکار» احتمالاً فاکتور را نمی‌بندد (مثلاً Stripe تا تکمیل کارت Pending می‌ماند)، یا موجودی/اعتبار نماینده در XMPlus کافی نیست، یا پنل خطای درگاه برمی‌گرداند. '.
                'در XMPlus همان فاکتور را دستی تسویه کنید، یا در Theme Settings شناسه درگاهی بگذارید که با اعتبار شما آنی Paid شود؛ سپس در VPNMarket دوباره «تایید و اجرا» بزنید.'
            );
        }
        if ($statusLabel === 'Pending' && ! $autoPayGatewayConfigured) {
            throw new RuntimeException(
                'XMPlus: فاکتور ساخته شد اما وضعیت آن Pending مانده و در تنظیمات فروشگاه «شناسه درگاه برای پرداخت خودکار فاکتور» (XMPlus) خالی است. '.
                'بدون فراخوانی invoice/pay فاکتور در پنل پرداخت نمی‌شود و سرویس فعال نمی‌شود. در پنل XMPlus شناسه عددی درگاهی که از API پرداخت آنی می‌پذیرد (مثلاً موجودی/درگاه داخلی) را در Theme Settings بگذارید، یا همان فاکتور را دستی در پنل پرداخت کنید و سپس سفارش را دوباره تأیید کنید.'
            );
        }

        if (strcasecmp($statusLabel, 'Paid') === 0) {
            if (is_array($paidWithoutSublinkFallbackContext)) {
                $notice = self::formatPaidInvoiceWithoutSublinkUserNotice($email, $invid, $paidWithoutSublinkFallbackContext);
                $api->log('warning', 'XMPlus: فاکتور Paid اما بدون لینک اشتراک در API — تحویل پیام راهنما به کاربر', [
                    'invid' => $invid,
                    'order_id' => $paidWithoutSublinkFallbackContext['order_id'] ?? null,
                    'wait_seconds' => $totalWait,
                ]);

                throw new RuntimeException(
                    $notice."\n\n"
                    .'❗️سفارش در VPNMarket در وضعیت pending نگه داشته شد تا اشتباهاً Paid بدون سرویس ثبت نشود. '
                    .'بعد از رفع منطق ساخت سرویس در پنل XMPlus دوباره «تأیید و اجرا» را بزنید.'
                );
            }
            throw new RuntimeException(
                'XMPlus: فاکتور در API با وضعیت Paid است اما پس از '.$totalWait.' ثانیه هنوز لینک اشتراک از account/info / service/info دیده نشد. '.
                'گاهی پنل XMPlus سرویس را با تأخیر می‌سازد یا تا زمان تأیید دستی در پنل معلق می‌ماند. چند دقیقه بعد در VPNMarket دوباره «تایید و اجرا» بزنید، یا در پنل XMPlus سرویس/اشتراک همان کاربر را بررسی کنید.'
            );
        }

        throw new RuntimeException(
            'XMPlus: پس از '.$totalWait.' ثانیه هنوز لینک اشتراک فعال نشد. وضعیت آخر فاکتور: '.$statusLabel.
            ' — اگر درگاه کریپتو/کارت است ابتدا پرداخت را در پنل یا QR تکمیل کنید؛ سپس از «سرویس‌های من» بررسی کنید یا با پشتیبانی تماس بگیرید.'
        );
    }

    /**
     * وقتی فاکتور Paid است ولی سرویس هنوز در API به‌عنوان Active نیامده، هر سطری که sublink دارد قبول می‌شود.
     *
     * @param  array<int, mixed>  $services
     * @return array{sublink: ?string, sid: ?int}
     */
    protected static function pickSublinkFromServicesRelaxed(array $services, int $expectPid): array
    {
        $candidates = [];
        foreach ($services as $s) {
            if (! is_array($s) || empty($s['sublink'])) {
                continue;
            }
            if ((int) ($s['packageid'] ?? 0) === $expectPid) {
                $candidates[] = $s;
            }
        }
        if (count($candidates) > 0) {
            usort($candidates, function ($a, $b) {
                $sidA = (int) ($a['sid'] ?? 0);
                $sidB = (int) ($b['sid'] ?? 0);

                return $sidB <=> $sidA;
            });
            $best = $candidates[0];
            $sid = isset($best['sid']) ? (int) $best['sid'] : null;

            return ['sublink' => (string) $best['sublink'], 'sid' => ($sid !== null && $sid > 0) ? $sid : null];
        }

        foreach ($services as $s) {
            if (is_array($s) && ! empty($s['sublink'])) {
                $sid = isset($s['sid']) ? (int) $s['sid'] : null;

                return ['sublink' => (string) $s['sublink'], 'sid' => ($sid !== null && $sid > 0) ? $sid : null];
            }
        }

        return ['sublink' => null, 'sid' => null];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected static function serviceIdFromInvoiceOrListRow(array $row): ?int
    {
        foreach (['serviceid', 'service_id', 'sid'] as $k) {
            if (! array_key_exists($k, $row)) {
                continue;
            }
            $v = $row[$k];
            if ($v === null || $v === '') {
                continue;
            }
            if (is_numeric($v)) {
                $n = (int) $v;

                return $n > 0 ? $n : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    protected static function sublinkFromServiceInfo(array $info): ?string
    {
        foreach (['sublink', 'subscription_url', 'sub_link', 'config_link'] as $k) {
            if (! empty($info[$k]) && is_string($info[$k])) {
                return (string) $info[$k];
            }
        }
        $nested = $info['data'] ?? $info['service'] ?? null;
        if (is_array($nested)) {
            foreach (['sublink', 'subscription_url', 'sub_link'] as $k) {
                if (! empty($nested[$k]) && is_string($nested[$k])) {
                    return (string) $nested[$k];
                }
            }
        }

        $servers = $info['servers'] ?? [];
        if (is_array($servers) && $servers !== []) {
            $first = $servers[0] ?? [];
            if (is_array($first) && ! empty($first['uri'])) {
                return (string) $first['uri'];
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $services
     */
    protected static function pickSublinkFromServices(array $services, int $expectPid, ?int $preferSid): ?string
    {
        foreach ($services as $s) {
            if (! is_array($s) || empty($s['sublink'])) {
                continue;
            }
            if ($preferSid !== null && (int) ($s['sid'] ?? 0) === $preferSid) {
                return (string) $s['sublink'];
            }
        }

        $candidates = [];
        foreach ($services as $s) {
            if (! is_array($s) || empty($s['sublink'])) {
                continue;
            }
            if ((int) ($s['packageid'] ?? 0) === $expectPid) {
                $candidates[] = $s;
            }
        }
        if (count($candidates) > 0) {
            usort($candidates, function ($a, $b) {
                $sidA = (int) ($a['sid'] ?? 0);
                $sidB = (int) ($b['sid'] ?? 0);

                return $sidB <=> $sidA;
            });

            return (string) $candidates[0]['sublink'];
        }

        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            if ($preferSid !== null && (int) ($s['sid'] ?? 0) === $preferSid && self::xmplusServiceRowLooksActive($s)) {
                if (! empty($s['sublink'])) {
                    return (string) $s['sublink'];
                }
            }
        }

        $activeCandidates = [];
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            if (self::xmplusServiceRowLooksActive($s) && (int) ($s['packageid'] ?? 0) === $expectPid && ! empty($s['sublink'])) {
                $activeCandidates[] = $s;
            }
        }
        if (count($activeCandidates) > 0) {
            usort($activeCandidates, function ($a, $b) {
                $sidA = (int) ($a['sid'] ?? 0);
                $sidB = (int) ($b['sid'] ?? 0);

                return $sidB <=> $sidA;
            });

            return (string) $activeCandidates[0]['sublink'];
        }

        foreach ($services as $s) {
            if (is_array($s) && self::xmplusServiceRowLooksActive($s) && ! empty($s['sublink'])) {
                return (string) $s['sublink'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $s
     */
    protected static function xmplusServiceRowLooksActive(array $s): bool
    {
        $raw = trim((string) ($s['status'] ?? ''));
        $st = strtolower($raw);
        if (in_array($st, ['inactive', 'expired', 'cancelled', 'canceled', 'disabled', 'suspended'], true)) {
            return false;
        }

        return $st === 'active' || $st === '' || $st === 'activating' || strcasecmp($raw, 'Active') === 0;
    }

    /**
     * @param  array<int, mixed>  $services
     */
    protected static function pickSidFromServices(array $services, int $expectPid, ?int $preferSid): ?int
    {
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            if ($preferSid !== null && (int) ($s['sid'] ?? 0) === $preferSid) {
                return (int) $s['sid'];
            }
        }

        $candidates = [];
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            if (self::xmplusServiceRowLooksActive($s) && (int) ($s['packageid'] ?? 0) === $expectPid) {
                $sid = (int) ($s['sid'] ?? 0);
                if ($sid > 0) {
                    $candidates[] = $sid;
                }
            }
        }
        if (count($candidates) > 0) {
            rsort($candidates);

            return $candidates[0];
        }

        foreach ($services as $s) {
            if (is_array($s) && self::xmplusServiceRowLooksActive($s) && isset($s['sid'])) {
                $sid = (int) $s['sid'];

                return $sid > 0 ? $sid : null;
            }
        }

        return null;
    }

    /**
     * موجودی نمایشی کیف پول از API: POST /api/client/account/info → data.money
     *
     * @return array<string, mixed>|null  null = پنل XMPlus فعال نیست (از balance محلی VPNMarket استفاده کنید)
     */
    public static function fetchXmplusWalletDisplay(User $user, Collection $settings): ?array
    {
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return null;
        }
        $panelBase = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');
        $cacheKey = 'xmplus_wallet_v1:'.$user->id;

        return Cache::remember($cacheKey, 45, function () use ($user, $settings, $panelBase) {
            $email = $user->xmplus_client_email;
            $pwd = $user->xmplus_client_password;
            if (! is_string($email) || $email === '' || ! is_string($pwd) || $pwd === '') {
                return [
                    'mode' => 'xmplus',
                    'linked' => false,
                    'panel_url' => $panelBase,
                    'money' => null,
                    'username' => '',
                ];
            }
            try {
                $api = self::fromSettings($settings);
                $acc = $api->accountInfo($email, $pwd);
                $payload = self::extractAccountPayload($acc);
                $money = $payload['money'] ?? (is_array($acc['data'] ?? null) ? ($acc['data']['money'] ?? '—') : '—');
                $username = $payload['username'] ?? (is_array($acc['data'] ?? null) ? ($acc['data']['username'] ?? '') : '');

                return [
                    'mode' => 'xmplus',
                    'linked' => true,
                    'panel_url' => $panelBase,
                    'money' => is_string($money) ? $money : json_encode($money),
                    'username' => is_string($username) ? $username : '',
                ];
            } catch (\Throwable $e) {
                Log::channel('xmplus')->warning('XMPlus fetchXmplusWalletDisplay: '.$e->getMessage(), ['user_id' => $user->id]);

                return [
                    'mode' => 'xmplus',
                    'linked' => true,
                    'panel_url' => $panelBase,
                    'money' => null,
                    'username' => '',
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * دادهٔ نمایش داشبورد وب از XMPlus (موجودی، سرویس‌ها، فاکتورها) وقتی پنل فعال XMPlus است.
     *
     * @return array<string, mixed>|null  null یعنی پنل XMPlus فعال نیست — از دادهٔ محلی VPNMarket استفاده کنید.
     */
    public static function fetchWebDashboardSnapshot(User $user, Collection $settings): ?array
    {
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return null;
        }
        $panelBase = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');
        $email = $user->xmplus_client_email;
        $pwd = $user->xmplus_client_password;
        if (! is_string($email) || $email === '' || ! is_string($pwd) || $pwd === '') {
            return [
                'mode' => 'xmplus',
                'linked' => false,
                'panel_url' => $panelBase,
            ];
        }
        try {
            $api = self::fromSettings($settings);
            $acc = $api->accountInfo($email, $pwd);
            $payload = self::extractAccountPayload($acc);
            $services = $payload['services'] ?? [];
            if (! is_array($services)) {
                $services = [];
            }
            $money = $payload['money'] ?? (is_array($acc['data'] ?? null) ? ($acc['data']['money'] ?? '—') : '—');
            $username = $payload['username'] ?? (is_array($acc['data'] ?? null) ? ($acc['data']['username'] ?? '') : '');
            $invoices = [];
            try {
                $invResp = $api->listInvoices($email, $pwd);
                $invoices = $invResp['invoices'] ?? [];
                if (! is_array($invoices)) {
                    $invoices = [];
                }
            } catch (\Throwable $e) {
                Log::channel('xmplus')->warning('XMPlus listInvoices (dashboard): '.$e->getMessage(), ['user_id' => $user->id]);
            }

            return [
                'mode' => 'xmplus',
                'linked' => true,
                'panel_url' => $panelBase,
                'money' => is_string($money) ? $money : json_encode($money),
                'username' => is_string($username) ? $username : '',
                'services' => $services,
                'invoices' => $invoices,
            ];
        } catch (\Throwable $e) {
            Log::channel('xmplus')->warning('XMPlus fetchWebDashboardSnapshot: '.$e->getMessage(), ['user_id' => $user->id]);

            return [
                'mode' => 'xmplus',
                'linked' => true,
                'panel_url' => $panelBase,
                'error' => $e->getMessage(),
                'services' => [],
                'invoices' => [],
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{services?: array<int, mixed>}
     */
    public static function extractAccountPayload(array $response): array
    {
        $data = $response['data'] ?? $response;
        if (! is_array($data)) {
            return [];
        }
        if (isset($data['services'])) {
            return $data;
        }
        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return $data;
    }

    /**
     * وقتی پنل XMPlus است و «پرداخت از درگاه‌های خود پنل در وب» فعال است، کارت/Plisio/دستی VPNMarket نباید نمایش داده شود.
     */
    public static function shouldUseXmplusGatewaysForWebCheckout(Collection $settings, ?Order $order): bool
    {
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return false;
        }
        if ($order === null || ! $order->plan_id) {
            return false;
        }

        return filter_var($settings->get('xmplus_web_gateway_checkout', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * بلافاصله پس از ثبت سفارش در VPNMarket، فاکتور Pending در XMPlus می‌سازد (یا همان فاکتور معتبر را نگه می‌دارد).
     * سپس با پرداخت آنلاین یا تأیید ادمین، همان invid با invoice/pay بسته می‌شود و سرویس ساخته می‌شود.
     *
     * @return array{ok: bool, error: ?string}
     */
    public static function ensurePendingShopInvoice(Order $order, Collection $settings): array
    {
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['ok' => true, 'error' => null];
        }
        if ($order->status !== 'pending' || ! $order->plan_id) {
            return ['ok' => true, 'error' => null];
        }

        $order->loadMissing(['plan', 'user']);
        if (! $order->plan || ! $order->user) {
            return ['ok' => false, 'error' => 'پلن یا کاربر سفارش یافت نشد.'];
        }

        $lock = Cache::lock('xmplus_ensure_inv:'.$order->id, 60);
        if (! $lock->get()) {
            return ['ok' => true, 'error' => null];
        }

        try {
            try {
                $api = self::fromSettings($settings);
            } catch (InvalidArgumentException $e) {
                return ['ok' => false, 'error' => $e->getMessage()];
            }

            $panelBase = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');
            $pid = self::resolvePackageId($order->plan, $settings);
            $billing = self::resolveBilling($order->plan);
            $aff = (string) ($settings->get('xmplus_affiliate_code') ?? '');

            if ($order->renews_order_id) {
                $originalOrder = Order::find($order->renews_order_id);
                if (! $originalOrder) {
                    return ['ok' => false, 'error' => 'سفارش اصلی برای تمدید یافت نشد.'];
                }
                self::webCheckoutEnsureRenewalInvoice($api, $order->user, $order, $originalOrder);
            } else {
                self::webCheckoutEnsureNewPurchaseInvoice(
                    $api,
                    $settings,
                    $order->user,
                    $order->plan,
                    $order,
                    $pid,
                    $billing,
                    $aff,
                    $panelBase
                );
            }

            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            Log::channel('xmplus')->warning('ensurePendingShopInvoice: '.$e->getMessage(), [
                'order_id' => $order->id,
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $lock->release();
        }
    }

    /**
     * فاکتور XMPlus (در صورت نیاز) + کش انتخاب درگاه برای صفحهٔ پرداخت وب.
     *
     * @return array{ok: bool, error: ?string, gateways: array<int, array{id: int, name: string, gateway: string}>}
     */
    public static function prepareWebPaymentGateways(Order $order, Collection $settings): array
    {
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['ok' => false, 'error' => 'نوع پنل XMPlus نیست.', 'gateways' => []];
        }
        $plan = $order->plan;
        if (! $plan) {
            return ['ok' => false, 'error' => 'این سفارش پلن ندارد.', 'gateways' => []];
        }
        $user = $order->user;
        if (! $user) {
            return ['ok' => false, 'error' => 'کاربر سفارش نامعتبر است.', 'gateways' => []];
        }

        $panelBase = rtrim((string) $settings->get('xmplus_panel_url', ''), '/');

        try {
            $api = self::fromSettings($settings);
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'gateways' => []];
        }

        $pid = self::resolvePackageId($plan, $settings);

        try {
            if ($order->renews_order_id) {
                $originalOrder = Order::find($order->renews_order_id);
                if (! $originalOrder) {
                    return ['ok' => false, 'error' => 'سفارش اصلی برای تمدید یافت نشد.', 'gateways' => []];
                }
                [$email, $passwdPlain, $invid, $knownSid, $credentialsMessage] = self::webCheckoutEnsureRenewalInvoice(
                    $api,
                    $user,
                    $order,
                    $originalOrder
                );
                $isRenewal = true;
                $originalOrderId = $originalOrder->id;
            } else {
                [$email, $passwdPlain, $invid, $knownSid, $credentialsMessage] = self::webCheckoutEnsureNewPurchaseInvoice(
                    $api,
                    $settings,
                    $user,
                    $plan,
                    $order,
                    $pid,
                    self::resolveBilling($plan),
                    (string) ($settings->get('xmplus_affiliate_code') ?? ''),
                    $panelBase
                );
                $isRenewal = false;
                $originalOrderId = null;
            }
        } catch (\Throwable $e) {
            Log::channel('xmplus')->warning('prepareWebPaymentGateways: '.$e->getMessage(), ['order_id' => $order->id]);

            return ['ok' => false, 'error' => $e->getMessage(), 'gateways' => []];
        }

        $gateways = $api->listGateways();
        if ($gateways === []) {
            return ['ok' => false, 'error' => 'لیست درگاه‌های فعال XMPlus از API خالی است؛ در پنل XMPlus درگاه فعال کنید.', 'gateways' => []];
        }

        $options = [];
        foreach ($gateways as $g) {
            $options[] = ['id' => $g['id'], 'name' => $g['name']];
        }

        Cache::put(self::invoiceContextCacheKey($order->id), [
            'email' => $email,
            'passwd' => $passwdPlain,
            'invid' => $invid,
            'pid' => $pid,
            'is_renewal' => $isRenewal,
            'original_order_id' => $originalOrderId,
            'known_sid' => $knownSid,
            'gateway_options' => $options,
            'credentials_message' => $credentialsMessage,
            'wallet_already_charged' => false,
            'order_id' => $order->id,
            'panel_base' => $panelBase,
        ], now()->addHours(48));

        return ['ok' => true, 'error' => null, 'gateways' => $gateways];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: ?int, 4: ?string}
     */
    protected static function webCheckoutEnsureNewPurchaseInvoice(
        XmplusService $api,
        Collection $settings,
        User $user,
        Plan $plan,
        Order $order,
        int $pid,
        string $billing,
        string $aff,
        string $panelBase,
        bool $allowXmplusStaleCredentialReset = true
    ): array {
        $domain = trim((string) $settings->get('xmplus_email_domain', ''));
        if ($domain === '') {
            throw new InvalidArgumentException('XMPlus: دامنه ایمیل (xmplus_email_domain) را در تنظیمات تم پر کنید.');
        }
        $domain = ltrim($domain, '@');

        $credentialsMessage = null;
        $existingInv = trim((string) ($order->xmplus_inv_id ?? ''));

        if ($existingInv !== '') {
            $email = $user->xmplus_client_email;
            $passwdPlain = $user->xmplus_client_password;
            if (! is_string($email) || $email === '' || ! is_string($passwdPlain) || $passwdPlain === '') {
                throw new RuntimeException('XMPlus: برای ادامهٔ پرداخت، حساب XMPlus کاربر ناقص است.');
            }
            try {
                $view = $api->invoiceView($email, $passwdPlain, $existingInv);
                $st = strtolower((string) data_get($view, 'invoice.status', ''));
                if ($st === 'pending') {
                    return [$email, $passwdPlain, $existingInv, null, null];
                }
            } catch (\Throwable) {
                // فاکتور نامعتبر — دوباره می‌سازیم
            }
            $order->forceFill(['xmplus_inv_id' => null])->save();
        }

        $email = $user->xmplus_client_email;
        $passwdPlain = null;

        if (! is_string($email) || $email === '') {
            $email = 'tg'.$user->id.'@'.$domain;
            $passwdPlain = Str::password(16, symbols: false);
            $name = self::xmplusDisplayName($user);
            $sendCode = filter_var($settings->get('xmplus_send_register_code', false), FILTER_VALIDATE_BOOLEAN);
            if ($sendCode) {
                $api->registerSendCode($name, $email);
            }
            $regCode = (string) ($settings->get('xmplus_registration_code') ?? '');
            $reg = $api->register($name, $email, $passwdPlain, $regCode, $aff);
            if (! self::apiOk($reg)) {
                if (self::apiIsEmailAlreadyRegistered($reg)) {
                    throw new RuntimeException(
                        'XMPlus: ایمیل '.$email.' از قبل در پنل ثبت است ولی فروشگاه هنوز رمز Client API این کاربر را ندارد. '
                        .'در پروفایل/ادمین همان رمز پنل را ذخیره کنید یا کاربر را در XMPlus حذف کنید. پاسخ: '.json_encode($reg, JSON_UNESCAPED_UNICODE)
                    );
                }
                throw new RuntimeException('XMPlus ثبت‌نام ناموفق: '.json_encode($reg, JSON_UNESCAPED_UNICODE));
            }
            $user->forceFill([
                'xmplus_client_email' => $email,
                'xmplus_client_password' => $passwdPlain,
            ])->save();
            $credentialsMessage = self::formatCredentialsMessage($email, $passwdPlain, $panelBase);
        } else {
            $passwdPlain = $user->xmplus_client_password;
            if (! is_string($passwdPlain) || $passwdPlain === '') {
                throw new RuntimeException('XMPlus: ایمیل کاربر ثبت است اما رمز ذخیره نشده است.');
            }
        }

        $inv = self::invoiceCreateWithBillingFallback($api, $email, $passwdPlain, $pid, $billing, (int) $order->id);
        if (! self::apiOk($inv)) {
            if (
                $allowXmplusStaleCredentialReset
                && self::apiIsEmailNotFoundOnClient($inv)
                && is_string($user->xmplus_client_email)
                && $user->xmplus_client_email !== ''
            ) {
                $user->forceFill([
                    'xmplus_client_email' => null,
                    'xmplus_client_password' => null,
                ])->save();

                return self::webCheckoutEnsureNewPurchaseInvoice(
                    $api,
                    $settings,
                    $user->fresh(),
                    $plan,
                    $order->fresh(),
                    $pid,
                    $billing,
                    $aff,
                    $panelBase,
                    false
                );
            }
            throw new RuntimeException('XMPlus ساخت فاکتور ناموفق: '.json_encode($inv, JSON_UNESCAPED_UNICODE));
        }
        $invid = $inv['invid'] ?? data_get($inv, 'data.invid');
        $invid = is_scalar($invid) ? (string) $invid : '';
        if ($invid === '') {
            throw new RuntimeException('XMPlus: شناسه فاکتور در پاسخ invoice/create نیست.');
        }
        $order->forceFill(['xmplus_inv_id' => $invid])->save();

        return [$email, $passwdPlain, $invid, null, $credentialsMessage];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: int, 4: ?string}
     */
    protected static function webCheckoutEnsureRenewalInvoice(
        XmplusService $api,
        User $user,
        Order $renewalOrder,
        Order $originalOrder
    ): array {
        $email = $user->xmplus_client_email ?? $originalOrder->panel_username;
        $passwdPlain = $user->xmplus_client_password;
        if (! is_string($email) || $email === '' || ! is_string($passwdPlain) || $passwdPlain === '') {
            throw new RuntimeException('XMPlus تمدید: اطلاعات ورود کاربر به پنل یافت نشد.');
        }
        $sidRaw = $originalOrder->panel_client_id ?? null;
        if ($sidRaw === null || $sidRaw === '') {
            throw new RuntimeException('XMPlus تمدید: شناسه سرویس (sid) روی سفارش اصلی نیست.');
        }
        $sid = (int) $sidRaw;
        if ($sid <= 0) {
            throw new RuntimeException('XMPlus تمدید: شناسه سرویس نامعتبر است.');
        }

        $existingInv = trim((string) ($renewalOrder->xmplus_inv_id ?? ''));
        if ($existingInv !== '') {
            try {
                $view = $api->invoiceView($email, $passwdPlain, $existingInv);
                $st = strtolower((string) data_get($view, 'invoice.status', ''));
                if ($st === 'pending') {
                    return [$email, $passwdPlain, $existingInv, $sid, null];
                }
            } catch (\Throwable) {
            }
            $renewalOrder->forceFill(['xmplus_inv_id' => null])->save();
        }

        $renew = $api->serviceRenew($email, $passwdPlain, $sid);
        if (! self::apiOk($renew)) {
            throw new RuntimeException('XMPlus تمدید ناموفق: '.json_encode($renew, JSON_UNESCAPED_UNICODE));
        }
        $invid = $renew['invid'] ?? null;
        $invid = is_scalar($invid) ? (string) $invid : '';
        if ($invid === '') {
            throw new RuntimeException('XMPlus تمدید: شناسه فاکتور (invid) در پاسخ نیست.');
        }
        $renewalOrder->forceFill(['xmplus_inv_id' => $invid])->save();

        return [$email, $passwdPlain, $invid, $sid, null];
    }

    /**
     * پرداخت وب با درگاه انتخابی؛ در صورت لینک مستقیم https به درگاه خارجی، polling اینجا انجام نمی‌شود.
     *
     * @return array{
     *   outcome: 'complete',
     *   final_config: string,
     *   panel_username: string,
     *   panel_client_id: ?string
     * }|array{outcome: 'redirect', url: string}|array{outcome: 'await_offsite', pay: array<string, mixed>}
     */
    public static function payInvoiceWithGatewayForWeb(XmplusService $api, array $ctx, int $gatewayId): array
    {
        $email = (string) ($ctx['email'] ?? '');
        $passwd = (string) ($ctx['passwd'] ?? '');
        $invid = (string) ($ctx['invid'] ?? '');
        $pid = (int) ($ctx['pid'] ?? 0);
        $knownSid = isset($ctx['known_sid']) ? (int) $ctx['known_sid'] : null;
        if ($knownSid !== null && $knownSid <= 0) {
            $knownSid = null;
        }
        if ($email === '' || $passwd === '' || $invid === '' || $pid <= 0) {
            throw new RuntimeException('XMPlus: بافتار پرداخت وب ناقص یا منقضی است؛ صفحه را از نو باز کنید.');
        }

        try {
            $payResponse = $api->invoicePay($email, $passwd, $invid, $gatewayId);
        } catch (\Throwable $e) {
            throw new RuntimeException('XMPlus invoice/pay ناموفق: '.$e->getMessage(), 0, $e);
        }
        if (! is_array($payResponse)) {
            $payResponse = [];
        }

        $data = $payResponse['data'] ?? null;
        if (is_string($data) && $data !== '' && preg_match('#^https?://#i', $data) === 1) {
            return ['outcome' => 'redirect', 'url' => $data];
        }

        if (self::invoicePayLooksAsync($payResponse)) {
            return ['outcome' => 'await_offsite', 'pay' => $payResponse];
        }

        $maxAttempts = 18;
        $sleepSeconds = 2;
        $pb = trim((string) ($ctx['panel_base'] ?? ''));
        $pollFb = ['panel_base' => $pb, 'order_id' => (int) ($ctx['order_id'] ?? 0)];
        $poll = self::pollForSublink($api, $email, $passwd, $invid, $pid, $knownSid, true, $maxAttempts, $sleepSeconds, false, $pollFb);
        $sid = $poll['sid'];

        return [
            'outcome' => 'complete',
            'final_config' => $poll['sublink'],
            'panel_username' => $email,
            'panel_client_id' => $sid !== null ? (string) $sid : null,
        ];
    }

    /**
     * پس از QR/کارت/تأیید ادمین: فقط polling تا آماده شدن لینک.
     *
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string}
     */
    public static function pollXmplusWebAfterOffsitePayment(XmplusService $api, array $ctx): array
    {
        $email = (string) ($ctx['email'] ?? '');
        $passwd = (string) ($ctx['passwd'] ?? '');
        $invid = (string) ($ctx['invid'] ?? '');
        $pid = (int) ($ctx['pid'] ?? 0);
        $knownSid = isset($ctx['known_sid']) ? (int) $ctx['known_sid'] : null;
        if ($knownSid !== null && $knownSid <= 0) {
            $knownSid = null;
        }
        if ($email === '' || $passwd === '' || $invid === '' || $pid <= 0) {
            throw new RuntimeException('XMPlus: بافتار تکمیل پرداخت ناقص است.');
        }

        $pb = trim((string) ($ctx['panel_base'] ?? ''));
        $pollFb = ['panel_base' => $pb, 'order_id' => (int) ($ctx['order_id'] ?? 0)];
        $poll = self::pollForSublink($api, $email, $passwd, $invid, $pid, $knownSid, true, 72, 5, false, $pollFb);
        $sid = $poll['sid'];

        return [
            'final_config' => $poll['sublink'],
            'panel_username' => $email,
            'panel_client_id' => $sid !== null ? (string) $sid : null,
        ];
    }
}
