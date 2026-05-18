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
        if ($st === 'active') {
            return true;
        }

        $expire = trim((string) ($row['expire_date'] ?? $row['expiredate'] ?? ''));
        if ($expire === '' || $expire === '0000-00-00' || $expire === '0000-00-00 00:00:00') {
            return false;
        }

        try {
            return (new \DateTime($expire)) > new \DateTime();
        } catch (\Throwable) {
            return false;
        }
    }
}
