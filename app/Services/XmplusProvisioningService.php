<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Collection;
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
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string, credentials_message: ?string, plain_password: ?string}
     */
    public static function provisionPurchase(
        Collection $settings,
        User $user,
        Plan $plan,
        Order $order,
        bool $isRenewal,
        ?Order $originalOrder
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
        ]);

        if ($isRenewal) {
            return self::doRenewal($api, $settings, $user, $plan, $originalOrder, $pid, $panelBase);
        }

        return self::doNewPurchase($api, $settings, $user, $plan, $pid, $billing, $aff, $panelBase);
    }

    /**
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string, credentials_message: ?string, plain_password: ?string}
     */
    protected static function doNewPurchase(
        XmplusService $api,
        Collection $settings,
        User $user,
        Plan $plan,
        int $pid,
        string $billing,
        string $aff,
        string $panelBase
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
        if ($autoPayConfigured) {
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
            $api->log('warning', 'XMPlus: xmplus_auto_pay_gateway_id خالی است — تا فاکتور در پنل پرداخت نشود سرویس Active نمی‌شود.', [
                'step' => 'invoice_pay_skipped',
                'invid' => $invid,
            ]);
        }

        $result = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, null, $autoPayConfigured);
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

    /**
     * @return array{final_config: string, panel_username: string, panel_client_id: ?string, credentials_message: ?string, plain_password: ?string}
     */
    protected static function doRenewal(
        XmplusService $api,
        Collection $settings,
        User $user,
        Plan $plan,
        ?Order $originalOrder,
        int $pid,
        string $panelBase
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
        if ($invid !== '' && $autoPayConfigured) {
            try {
                $api->invoicePay($email, $passwdPlain, $invid, (int) $gatewayId);
            } catch (\Throwable $e) {
                $api->log('warning', 'XMPlus تمدید: invoice/pay خطا', ['error' => $e->getMessage()]);
            }
        }

        if ($invid !== '') {
            $poll = self::pollForSublink($api, $email, $passwdPlain, $invid, $pid, $sid, $autoPayConfigured);
            $sublink = $poll['sublink'];
            $outSid = $poll['sid'] ?? $sid;
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
        bool $autoPayGatewayConfigured = false
    ): array {
        $lastStatus = null;
        $attempts = 18;

        for ($i = 0; $i < $attempts; $i++) {
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
            sleep(2);
        }

        $statusLabel = (string) ($lastStatus ?? 'نامشخص');
        if ($statusLabel === 'Pending' && ! $autoPayGatewayConfigured) {
            throw new RuntimeException(
                'XMPlus: فاکتور ساخته شد اما وضعیت آن Pending مانده و در تنظیمات فروشگاه «شناسه درگاه برای پرداخت خودکار فاکتور» (XMPlus) خالی است. '.
                'بدون فراخوانی invoice/pay فاکتور در پنل پرداخت نمی‌شود و سرویس فعال نمی‌شود. در پنل XMPlus شناسه عددی درگاهی که از API پرداخت آنی می‌پذیرد (مثلاً موجودی/درگاه داخلی) را در Theme Settings بگذارید، یا همان فاکتور را دستی در پنل پرداخت کنید و سپس سفارش را دوباره تأیید کنید.'
            );
        }

        throw new RuntimeException(
            'XMPlus: پس از '.($attempts * 2).' ثانیه هنوز لینک اشتراک فعال نشد. وضعیت آخر فاکتور: '.$statusLabel.
            ' — اگر پرداخت خودکار فعال است شناسه درگاه و لاگ invoice/pay را بررسی کنید؛ در غیر این صورت فاکتور را در پنل XMPlus پرداخت کنید.'
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
