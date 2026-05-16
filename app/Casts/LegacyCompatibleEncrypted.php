<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * مثل encrypted لاراول، ولی اگر decrypt نشد (import با APP_KEY دیگر یا متن ساده قدیمی) خطا نمی‌دهد.
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

        try {
            return (string) Crypt::decryptString($value);
        } catch (DecryptException) {
            try {
                $decrypted = Crypt::decrypt($value, false);

                return is_string($decrypted) ? $decrypted : null;
            } catch (DecryptException) {
                $payload = json_decode($value, true);
                if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
                    return null;
                }

                return $value;
            }
        }
    }

    public function set(mixed $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
