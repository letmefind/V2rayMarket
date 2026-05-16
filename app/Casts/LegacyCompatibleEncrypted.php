<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * مثل encrypted لاراول؛ اگر با APP_KEY فعلی باز نشد، blob رمزنگاری‌شده را به کاربر نشان نمی‌دهد.
 */
class LegacyCompatibleEncrypted implements CastsAttributes
{
    public function get(mixed $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $plain = $this->tryDecrypt($value);
        if ($plain !== null) {
            return $plain;
        }

        if ($this->looksLikeLaravelEncryptedPayload($value)) {
            return null;
        }

        return $value;
    }

    public function set(mixed $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }

    private function tryDecrypt(string $value): ?string
    {
        try {
            return (string) Crypt::decryptString($value);
        } catch (DecryptException) {
            try {
                $decrypted = Crypt::decrypt($value, false);

                return is_string($decrypted) ? $decrypted : null;
            } catch (DecryptException) {
                return null;
            }
        }
    }

    private function looksLikeLaravelEncryptedPayload(string $value): bool
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
