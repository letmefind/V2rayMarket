<?php

namespace App\Support;

use App\Models\User;
use App\Services\XmplusService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * بازیابی رمز XMPlus پس از import یا چرخش APP_KEY؛ در غیر این صورت ثبت هویت جایگزین برای خرید جدید.
 */
final class XmplusCredentialRecovery
{
    public static function rehydratePassword(User $user): ?string
    {
        $current = trim((string) ($user->xmplus_client_password ?? ''));
        if ($current !== '') {
            return $current;
        }

        $raw = $user->getRawOriginal('xmplus_client_password');
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        if (! LegacyAppKeyDecryptor::looksLikeLaravelEncryptedPayload($raw)) {
            $user->forceFill(['xmplus_client_password' => $raw])->save();

            return $raw;
        }

        $plain = LegacyAppKeyDecryptor::tryDecrypt($raw);
        if ($plain === null || $plain === '') {
            return null;
        }

        $user->forceFill(['xmplus_client_password' => $plain])->save();

        return $plain;
    }

    public static function userHasXmplusEmailWithoutPassword(User $user): bool
    {
        $email = trim((string) ($user->xmplus_client_email ?? ''));

        return $email !== '' && trim((string) ($user->xmplus_client_password ?? '')) === '';
    }

    /**
     * وقتی ایمیل در XMPlus هست ولی رمز محلی از دست رفته، حساب Client API جدید با پسوند می‌سازد.
     *
     * @return array{email: string, password: string, credentials_message: string}
     */
    public static function registerAlternateXmplusIdentity(
        XmplusService $api,
        User $user,
        string $domain,
        string $aff,
        string $panelBase,
        string $regCode,
        bool $sendCode
    ): array {
        $domain = ltrim($domain, '@');
        $name = self::xmplusDisplayName($user);
        $lastError = 'register failed';

        for ($attempt = 0; $attempt < 8; $attempt++) {
            $suffix = $attempt === 0
                ? substr(hash('crc32b', (string) ($user->instance_id ?? '').'|'.$user->id), 0, 6)
                : Str::lower(Str::random(6));
            $email = 'tg'.$user->id.'.'.$suffix.'@'.$domain;
            $passwdPlain = Str::password(16, symbols: false);

            if ($sendCode) {
                $api->registerSendCode($name, $email);
            }

            $reg = $api->register($name, $email, $passwdPlain, $regCode, $aff);
            if (self::apiOk($reg)) {
                $user->forceFill([
                    'xmplus_client_email' => $email,
                    'xmplus_client_password' => $passwdPlain,
                ])->save();

                $api->log('warning', 'XMPlus: هویت جایگزین برای کاربر import‌شده ساخته شد (رمز قدیمی قابل بازیابی نبود)', [
                    'user_id' => $user->id,
                    'email' => $email,
                ]);

                return [
                    'email' => $email,
                    'password' => $passwdPlain,
                    'credentials_message' => self::formatCredentialsMessage($email, $passwdPlain, $panelBase),
                ];
            }

            $lastError = json_encode($reg, JSON_UNESCAPED_UNICODE);
            if (! self::apiIsEmailAlreadyRegistered($reg)) {
                break;
            }
        }

        throw new RuntimeException(
            'XMPlus: رمز حساب قبلی ('.(string) $user->xmplus_client_email.') در فروشگاه موجود نیست و ثبت هویت جایگزین هم ناموفق بود. '
            .'رمز را از پنل XMPlus در پروفایل کاربر (ID '.$user->id.') ذخیره کنید یا کاربر قدیمی را در XMPlus حذف کنید. آخرین پاسخ: '.$lastError
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function apiIsEmailAlreadyRegistered(array $row): bool
    {
        $msg = strtolower((string) ($row['message'] ?? ''));

        return str_contains($msg, 'already registered') || str_contains($msg, 'already been registered');
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function apiOk(array $row): bool
    {
        $st = strtolower((string) ($row['status'] ?? ''));
        $code = (int) ($row['code'] ?? 0);

        return $st === 'success' || $code === 100;
    }

    protected static function xmplusDisplayName(User $user): string
    {
        $name = trim((string) ($user->name ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'User '.$user->id;
    }

    protected static function formatCredentialsMessage(string $email, string $password, string $panelBase): string
    {
        $panelBase = rtrim($panelBase, '/');

        return "🔐 حساب XMPlus\n\n"
            ."👤 ایمیل: `{$email}`\n"
            ."🔑 رمز: `{$password}`\n\n"
            ."🌐 پنل: {$panelBase}";
    }
}
