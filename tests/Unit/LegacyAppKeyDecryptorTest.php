<?php

namespace Tests\Unit;

use App\Support\LegacyAppKeyDecryptor;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

class LegacyAppKeyDecryptorTest extends TestCase
{
    public function test_decrypts_with_previous_app_key(): void
    {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        config(['app.key' => $newKey]);
        config(['app.previous_keys' => [$oldKey]]);

        $normalized = base64_decode(substr($oldKey, 7), true);
        $encrypter = new Encrypter($normalized, 'AES-256-CBC');
        $cipher = $encrypter->encryptString('secret-pass');

        $plain = LegacyAppKeyDecryptor::tryDecrypt($cipher);

        $this->assertSame('secret-pass', $plain);
    }

    public function test_returns_null_for_invalid_payload(): void
    {
        $this->assertNull(LegacyAppKeyDecryptor::tryDecrypt('not-encrypted'));
    }
}
