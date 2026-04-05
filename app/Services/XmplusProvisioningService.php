<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Support\XmplusGatewayTelegram;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * ساخت/تمدید سرویس در XMPlus از طریق Client API پس از پرداخت در سایت یا ربات.
 */
class XmplusProvisioningService
{
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

        $poll = self::pollForSublink($api, $email, $passwd, $invid, $pid, $knownSid, true, $maxAttempts, $sleepSeconds);
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
     * @param  callable(): array<string, mixed>  $invoicePay
     * @return array<string, mixed>
     */
    protected static function runInvoicePayStrictForShopCollected(XmplusService $api, callable $invoicePay): array
    {
        try {
            $pay = $invoicePay();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'XMPlus invoice/pay خطا داد؛ فاکتور در پنل باز می‌ماند. درگاه «شناسه پرداخت خودکار فاکتور» و Client API را بررسی کنید. پیام: '.$e->getMessage(),
                0,
                $e
            );
        }
        if (! self::invoicePayResponseLooksSuccessful($pay)) {
            throw new RuntimeException(
                'XMPlus invoice/pay پاسخ موفق شناخته نشد؛ فاکتور احتمالاً Pending مانده. پاسخ خام: '.json_encode($pay, JSON_UNESCAPED_UNICODE)
            );
        }

        return $pay;
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
        bool $shopPaymentAlreadyCollected = false
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
                throw new RuntimeException('XMPlus ثبت‌نام ناموفق: '.json_encode($reg, JSON_UNESCAPED_UNICODE));
            }

            $user->forceFill([
                'xmplus_client_email' => $email,
                'xmplus_client_password' => $passwdPlain,
            ])->save();

            $credentialsMessage = self::formatCredentialsMessage($email, $passwdPlain, $panelBase);
            $api->log('info', 'XMPlus کاربر جدید ثبت شد', ['step' => 'register_ok', 'email' => $email]);
        } else {
            $passwdPlain = $user->xmplus_client_password;
            if (! is_string($passwdPlain) || $passwdPlain === '') {
                throw new RuntimeException('XMPlus: ایمیل کاربر در دیتابیس هست اما رمز ذخیره‌شده نیست؛ امکان ساخت فاکتور نیست.');
            }
            $api->log('info', 'XMPlus استفاده از حساب موجود', ['step' => 'existing_user', 'email' => $email]);
        }

        $inv = $api->invoiceCreate($email, $passwdPlain, $pid, $billing, '', 1);
        if (! self::apiOk($inv)) {
            throw new RuntimeException('XMPlus ساخت فاکتور ناموفق: '.json_encode($inv, JSON_UNESCAPED_UNICODE));
        }
        $invid = $inv['invid'] ?? data_get($inv, 'data.invid');
        if (! is_string($invid) || $invid === '') {
            $invid = is_scalar($inv['invid'] ?? null) ? (string) $inv['invid'] : '';
        }
        if ($invid === '') {
            throw new RuntimeException('XMPlus: شناسه فاکتور (invid) در پاسخ invoice/create نیست.');
        }

        $gatewayId = $settings->get('xmplus_auto_pay_gateway_id');
        $autoPayConfigured = $gatewayId !== null && $gatewayId !== '' && is_numeric((string) $gatewayId);

        if ($shopPaymentAlreadyCollected) {
            if (! $autoPayConfigured) {
                throw new RuntimeException(
                    'XMPlus: مشتری در فروشگاه شما قبلاً پرداخت کرده؛ نباید دوباره در XMPlus پرداخت کند. '
                    .'در تنظیمات تم، فیلد «شناسه درگاه برای پرداخت خودکار فاکتور» را با شناسهٔ عددی درگاهی پر کنید که فاکتور را با اعتبار/موجودی شما در پنل XMPlus می‌بندد (مثلاً موجودی نمایندگی)، نه درگاه کارت مشتری. '
                    .'منوی انتخاب درگاه در تلگرام فقط برای حالت‌هایی است که هنوز در سایت پرداخت نشده باشد.'
                );
            }
            $pay = self::runInvoicePayStrictForShopCollected(
                $api,
                function () use ($api, $email, $passwdPlain, $invid, $gatewayId) {
                    return $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                }
            );
            $api->log('info', 'XMPlus invoice/pay (تسویه پس از پرداخت فروشگاه)', [
                'step' => 'invoice_pay_shop_collected',
                'invid' => $invid,
                'gatewayid' => (int) $gatewayId,
                'response_summary' => array_keys($pay),
            ]);
            $async = self::invoicePayLooksAsync($pay);
            $maxAttempts = $async ? 72 : 18;
            $sleepSeconds = $async ? 5 : 2;
            $result = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, null, true, $maxAttempts, $sleepSeconds);
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
            $pay = [];
            try {
                $pay = $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                $api->log('info', 'XMPlus invoice/pay فراخوانی شد', ['step' => 'invoice_pay', 'response_summary' => is_array($pay) ? array_keys($pay) : 'n/a']);
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus invoice/pay خطا (ادامه با polling)', [
                    'step' => 'invoice_pay_error',
                    'error' => $e->getMessage(),
                ]);
            }
            $async = self::invoicePayLooksAsync($pay);
            $maxAttempts = $async ? 72 : 18;
            $sleepSeconds = $async ? 5 : 2;
            $result = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, null, true, $maxAttempts, $sleepSeconds);
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

        $renew = $api->serviceRenew($email, $passwdPlain, $sid);
        if (! self::apiOk($renew)) {
            throw new RuntimeException('XMPlus تمدید ناموفق: '.json_encode($renew, JSON_UNESCAPED_UNICODE));
        }
        $invid = $renew['invid'] ?? null;
        $invid = is_scalar($invid) ? (string) $invid : '';

        $gatewayId = $settings->get('xmplus_auto_pay_gateway_id');
        $autoPayConfigured = $gatewayId !== null && $gatewayId !== '' && is_numeric((string) $gatewayId);

        if ($shopPaymentAlreadyCollected && $invid !== '' && ! $autoPayConfigured) {
            throw new RuntimeException(
                'XMPlus تمدید: مشتری در فروشگاه پرداخت کرده؛ برای بستن فاکتور تمدید در XMPlus باید «شناسه درگاه پرداخت خودکار فاکتور» (تسویه با اعتبار فروشنده) را در تنظیمات تم پر کنید.'
            );
        }

        if ($invid !== '' && $autoPayConfigured) {
            if ($shopPaymentAlreadyCollected) {
                $pay = self::runInvoicePayStrictForShopCollected(
                    $api,
                    function () use ($api, $email, $passwdPlain, $invid, $gatewayId) {
                        return $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
                    }
                );
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
            $poll = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, $sid, true, $maxAttempts, $sleepSeconds);
            $sublink = $poll['sublink'];
            $outSid = $poll['sid'] ?? $sid;

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
            ], now()->addHours(48));

            return [
                'phase' => 'await_gateway',
                'order_id' => $renewalOrder->id,
                'credentials_message' => null,
            ];
        }

        if ($invid !== '') {
            $poll = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, $sid, false);
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
        int $sleepSeconds = 2
    ): array {
        $lastStatus = null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            try {
                $view = $api->invoiceView($email, $passwd, $invid);
                $inv = $view['invoice'] ?? [];
                $lastStatus = $inv['status'] ?? null;
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

            $accPayload = self::extractAccountPayload($api->accountInfo($email, $passwd));
            $services = $accPayload['services'] ?? [];

            $sublink = self::pickSublinkFromServices($services, $expectPid, $knownSid);
            if ($sublink !== null) {
                $sid = self::pickSidFromServices($services, $expectPid, $knownSid);

                return ['sublink' => $sublink, 'sid' => $sid];
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
        $totalWait = $maxAttempts * $sleepSeconds;
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

        throw new RuntimeException(
            'XMPlus: پس از '.$totalWait.' ثانیه هنوز لینک اشتراک فعال نشد. وضعیت آخر فاکتور: '.$statusLabel.
            ' — اگر درگاه کریپتو/کارت است ابتدا پرداخت را در پنل یا QR تکمیل کنید؛ سپس از «سرویس‌های من» بررسی کنید یا با پشتیبانی تماس بگیرید.'
        );
    }

    /**
     * @param  array<string, mixed>  $info
     */
    protected static function sublinkFromServiceInfo(array $info): ?string
    {
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
            if (! is_array($s)) {
                continue;
            }
            if ($preferSid !== null && (int) ($s['sid'] ?? 0) === $preferSid && ($s['status'] ?? '') === 'Active') {
                if (! empty($s['sublink'])) {
                    return (string) $s['sublink'];
                }
            }
        }
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            if (($s['status'] ?? '') === 'Active' && (int) ($s['packageid'] ?? 0) === $expectPid && ! empty($s['sublink'])) {
                return (string) $s['sublink'];
            }
        }
        foreach ($services as $s) {
            if (is_array($s) && ($s['status'] ?? '') === 'Active' && ! empty($s['sublink'])) {
                return (string) $s['sublink'];
            }
        }

        return null;
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
        foreach ($services as $s) {
            if (! is_array($s)) {
                continue;
            }
            if (($s['status'] ?? '') === 'Active' && (int) ($s['packageid'] ?? 0) === $expectPid) {
                return (int) ($s['sid'] ?? 0) ?: null;
            }
        }
        foreach ($services as $s) {
            if (is_array($s) && ($s['status'] ?? '') === 'Active' && isset($s['sid'])) {
                return (int) $s['sid'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array{services?: array<int, mixed>}
     */
    protected static function extractAccountPayload(array $response): array
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
}
