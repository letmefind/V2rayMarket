<?php

namespace App\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * تلاش برای باز کردن مقادیر رمزنگاری‌شده با APP_KEY فعلی و APP_PREVIOUS_KEYS.
 */
final class LegacyAppKeyDecryptor
{
    /**
     * @return list<string>
     */
    public static function encryptionKeys(): array
    {
        $keys = [];
        $current = config('app.key');
        if (is_string($current) && $current !== '') {
            $keys[] = $current;
        }
        foreach (config('app.previous_keys', []) as $key) {
            if (! is_string($key) || $key === '' || in_array($key, $keys, true)) {
                continue;
            }
            $keys[] = $key;
        }

        return $keys;
    }

    public static function tryDecrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (self::encryptionKeys() as $key) {
            $plain = self::tryDecryptWithKey($value, $key);
            if ($plain !== null) {
                return $plain;
            }
        }

        return null;
    }

    private static function tryDecryptWithKey(string $value, string $key): ?string
    {
        try {
            $encrypter = new Encrypter(self::normalizeKey($key), (string) config('app.cipher', 'AES-256-CBC'));

            return (string) $encrypter->decryptString($value);
        } catch (DecryptException) {
            try {
                $encrypter = new Encrypter(self::normalizeKey($key), (string) config('app.cipher', 'AES-256-CBC'));
                $decrypted = $encrypter->decrypt($value, false);

                return is_string($decrypted) ? $decrypted : null;
            } catch (DecryptException) {
                return null;
            }
        }
    }

    private static function normalizeKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded !== false ? $decoded : $key;
        }

        return $key;
    }

    public static function looksLikeLaravelEncryptedPayload(string $value): bool
    {
        $payload = json_decode($value, true);
        if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
            return true;
        }

        if (! preg_match('/^[A-Za-z0-9+\/]+=*$/', $value)) {
            return false;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }
}
