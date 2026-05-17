<?php

namespace App\Support;

use App\Models\Order;
use App\Models\User;
use App\Services\XmplusProvisioningService;
use Illuminate\Support\Collection;

/**
 * همان منطق دکمهٔ Renew در پنل XMPlus:
 * - سرویس منقضی (status = -1 یا معادل expired)
 * - یا ترافیک باقی‌مانده ≤ ۱۰٪ کل ترافیک (u ≤ traffic × 0.1)
 */
final class XmplusRenewalEligibility
{
    public const LOW_TRAFFIC_FRACTION = 0.1;

    /** متن برای کاربر وقتی هنوز حجم کافی دارد (تمدید مجاز نیست). */
    public const USER_DENIAL_MESSAGE = <<<'TXT'
ℹ️ سرویس شما هنوز حجم دارد.

تمدید وقتی امکان‌پذیر است که کمتر از ۱۰٪ حجم باقی مانده باشد.

توجه: در صورت تمدید، حجم باقی‌ماندهٔ قبلی به دورهٔ جدید اضافه نمی‌شود؛ بهتر است تا پایان حجم صبر کنید.
TXT;

    /** وقتی در منوی تمدید هیچ سرویس واجد شرایطی نیست. */
    public const USER_EMPTY_RENEW_MENU_MESSAGE = <<<'TXT'
⚠️ فعلاً سرویسی برای تمدید در دسترس نیست.

سرویس‌های شما هنوز حجم دارند. هر زمان کمتر از ۱۰٪ حجم باقی ماند می‌توانید تمدید کنید.

توجه: در صورت تمدید، حجم باقی‌ماندهٔ قبلی به دورهٔ جدید اضافه نمی‌شود؛ بهتر است تا پایان حجم صبر کنید.
TXT;

    public static function userDenialMessage(): string
    {
        return self::USER_DENIAL_MESSAGE;
    }

    public static function userEmptyRenewMenuMessage(): string
    {
        return self::USER_EMPTY_RENEW_MENU_MESSAGE;
    }

    /**
     * @return array{allowed: bool, reason: string, expired: bool, low_traffic: bool, error: ?string}
     */
    public static function evaluateForOrder(User $user, Order $paidOrder, Collection $settings): array
    {
        $base = [
            'allowed' => true,
            'reason' => '',
            'expired' => false,
            'low_traffic' => false,
            'error' => null,
        ];

        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return $base;
        }

        $sid = (int) ($paidOrder->panel_client_id ?? 0);
        if ($sid <= 0) {
            return [
                'allowed' => false,
                'reason' => 'شناسه سرویس (sid) روی این سفارش ذخیره نشده است.',
                'expired' => false,
                'low_traffic' => false,
                'error' => null,
            ];
        }

        $email = trim((string) ($user->xmplus_client_email ?? $paidOrder->panel_username ?? ''));
        XmplusCredentialRecovery::rehydratePassword($user);
        $user->refresh();
        $passwd = $user->xmplus_client_password;
        if ($email === '' || ! is_string($passwd) || $passwd === '') {
            return [
                'allowed' => false,
                'reason' => 'اطلاعات ورود XMPlus برای این کاربر کامل نیست.',
                'expired' => false,
                'low_traffic' => false,
                'error' => null,
            ];
        }

        try {
            $api = XmplusProvisioningService::fromSettings($settings);
            $row = $api->serviceInfo($email, $passwd, $sid);
        } catch (\Throwable $e) {
            return [
                'allowed' => false,
                'reason' => 'امکان بررسی وضعیت سرویس از XMPlus نیست. کمی بعد دوباره تلاش کنید.',
                'expired' => false,
                'low_traffic' => false,
                'error' => $e->getMessage(),
            ];
        }

        return self::evaluateFromServiceRow($row);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{allowed: bool, reason: string, expired: bool, low_traffic: bool, error: ?string}
     */
    public static function evaluateFromServiceRow(array $row): array
    {
        if (isset($row['data']) && is_array($row['data'])) {
            $row = $row['data'];
        }

        $expired = self::serviceRowIsExpired($row);
        $lowTraffic = self::serviceRowHasLowRemainingTraffic($row);
        $allowed = $expired || $lowTraffic;

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? '' : self::userDenialMessage(),
            'expired' => $expired,
            'low_traffic' => $lowTraffic,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function serviceRowIsExpired(array $row): bool
    {
        $status = $row['status'] ?? null;
        if (is_numeric($status) && (int) $status === -1) {
            return true;
        }

        $st = strtolower(trim((string) $status));
        if ($st === '') {
            return false;
        }

        foreach (['expired', 'inactive', 'cancelled', 'canceled', 'disabled', 'suspended'] as $needle) {
            if (str_contains($st, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * u = ترافیک باقی‌مانده؛ شرط پنل: u ≤ traffic × 0.1
     *
     * @param  array<string, mixed>  $row
     */
    public static function serviceRowHasLowRemainingTraffic(array $row): bool
    {
        $total = self::parseDataSizeToBytes((string) ($row['traffic'] ?? ''));
        if ($total <= 0) {
            return false;
        }

        $used = self::parseDataSizeToBytes((string) ($row['used_traffic'] ?? $row['used'] ?? $row['u'] ?? ''));
        $remaining = max(0, $total - $used);

        return $remaining <= (int) floor($total * self::LOW_TRAFFIC_FRACTION);
    }

    public static function parseDataSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '—' || $value === '-') {
            return 0;
        }

        if (preg_match('/unlimited|نامحدود/i', $value)) {
            return PHP_INT_MAX;
        }

        if (preg_match('/^([\d.,]+)\s*(B|KB|MB|GB|TB|K|M|G|T)?$/i', $value, $m)) {
            $num = (float) str_replace(',', '', $m[1]);
            $unit = strtoupper($m[2] ?? 'B');
            if ($unit === 'K') {
                $unit = 'KB';
            }
            if ($unit === 'M') {
                $unit = 'MB';
            }
            if ($unit === 'G') {
                $unit = 'GB';
            }
            if ($unit === 'T') {
                $unit = 'TB';
            }

            $mult = match ($unit) {
                'TB' => 1024 ** 4,
                'GB' => 1024 ** 3,
                'MB' => 1024 ** 2,
                'KB' => 1024,
                default => 1,
            };

            return (int) round($num * $mult);
        }

        return 0;
    }
}
