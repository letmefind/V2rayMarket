<?php

namespace App\Support;

/**
 * تفسیر پاسخ service/info برای تمدید (XMPlus Client API §9).
 */
final class XmplusServicePanelState
{
    /**
     * @param  array<string, mixed>  $row
     */
    public static function responseIndicatesExistingService(array $row): bool
    {
        $code = $row['code'] ?? null;
        if ($code !== null && (int) $code !== 100) {
            return false;
        }

        $sid = $row['sid'] ?? $row['serviceid'] ?? null;
        if ($sid !== null && (int) $sid > 0) {
            return true;
        }

        $st = strtolower((string) ($row['status'] ?? ''));

        return in_array($st, ['active', 'expired', 'suspended', 'cancelled'], true);
    }

    /**
     * آیا پس از pay واقعاً تمدید روی پنل اعمال شده (تاریخ انقضا یا وضعیت Active).
     *
     * @param  array<string, mixed>  $row
     */
    public static function renewalAppliedOnPanel(array $row): bool
    {
        if (! self::responseIndicatesExistingService($row)) {
            return false;
        }

        $st = strtolower((string) ($row['status'] ?? ''));

        // XMPlus گاهی status=Expired ولی expire_date آینده دارد — بدون Active/ترافیک تمدید واقعی نیست.
        return $st === 'active';
    }
}
