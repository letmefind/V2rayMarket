<?php

namespace App\Casts;

use App\Support\LegacyAppKeyDecryptor;
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

        if (LegacyAppKeyDecryptor::looksLikeLaravelEncryptedPayload($value)) {
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
                return LegacyAppKeyDecryptor::tryDecrypt($value);
            }
        }
    }
}
