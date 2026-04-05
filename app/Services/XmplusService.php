<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * XMPlus Client API — مستندات: https://docs.xmplus.dev/api/client.html
 */
class XmplusService
{
    protected string $baseUrl;

    public function __construct(
        string $baseUrl,
        protected string $clientApiKey
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    protected function logger()
    {
        return Log::channel('xmplus');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger()->{$level}($message, $context);
    }

    public function getToken(bool $refresh = false): string
    {
        $cacheKey = 'xmplus_jwt_'.md5($this->baseUrl.'|'.$this->clientApiKey);

        if (! $refresh && Cache::has($cacheKey)) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                $this->log('debug', 'XMPlus token: cache hit', ['cache_key_suffix' => substr(md5($cacheKey), 0, 8)]);

                return $cached;
            }
        }

        $url = $this->baseUrl.'/api/client/token';
        $headerHash = md5($this->clientApiKey);

        $this->log('info', 'XMPlus request: POST /api/client/token', [
            'step' => 'token',
            'url' => $url,
            'header' => 'xmplus-authorization',
            'header_value_is_md5_of_key' => true,
        ]);

        $response = Http::timeout(45)
            ->acceptJson()
            ->withHeaders([
                'xmplus-authorization' => $headerHash,
            ])
            ->post($url, (object) []);

        $body = $response->json();
        $this->log('info', 'XMPlus response: POST /api/client/token', [
            'step' => 'token',
            'http_status' => $response->status(),
            'body' => $body,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('XMPlus token: HTTP '.$response->status());
        }

        $token = data_get($body, 'data.token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('XMPlus token: فیلد data.token در پاسخ نیست.');
        }

        $ttl = 3600;
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true) ?: '', true);
            if (is_array($payload) && isset($payload['exp'])) {
                $ttl = max(120, (int) $payload['exp'] - time() - 120);
            }
        }
        Cache::put($cacheKey, $token, $ttl);
        $this->log('info', 'XMPlus token: cached', ['ttl_seconds' => $ttl]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function postClient(string $path, array $body, string $step): array
    {
        $token = $this->getToken();
        $url = $this->baseUrl.$path;

        $this->log('info', "XMPlus request: POST {$path}", [
            'step' => $step,
            'url' => $url,
            'body' => $this->redactBody($body),
        ]);

        $response = Http::timeout(60)
            ->acceptJson()
            ->withToken($token)
            ->post($url, $body);

        $parsed = $response->json();
        if (! is_array($parsed)) {
            $parsed = ['_raw' => $response->body()];
        }

        $this->log('info', "XMPlus response: POST {$path}", [
            'step' => $step,
            'http_status' => $response->status(),
            'body' => $this->redactBody($parsed),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("XMPlus {$step}: HTTP {$response->status()} — ".json_encode($parsed, JSON_UNESCAPED_UNICODE));
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function redactBody(array $data): array
    {
        $out = $data;
        foreach (['passwd', 'password', 'token'] as $k) {
            if (isset($out[$k]) && is_string($out[$k])) {
                $out[$k] = '***REDACTED(len='.strlen($out[$k]).')';
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function registerSendCode(string $name, string $email): array
    {
        return $this->postClient('/api/client/register/sendcode', [
            'name' => $name,
            'email' => $email,
        ], 'register_sendcode');
    }

    /**
     * @return array<string, mixed>
     */
    public function register(string $name, string $email, string $passwd, string $code, string $aff = ''): array
    {
        $body = [
            'name' => $name,
            'email' => $email,
            'passwd' => $passwd,
            'code' => $code,
        ];
        if ($aff !== '') {
            $body['aff'] = $aff;
        }

        return $this->postClient('/api/client/register', $body, 'register');
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceCreate(string $email, string $passwd, int $pid, string $billing, string $coupon = '', int $qty = 1): array
    {
        return $this->postClient('/api/client/invoice/create', [
            'email' => $email,
            'passwd' => $passwd,
            'pid' => $pid,
            'billing' => $billing,
            'coupon' => $coupon,
            'qty' => $qty,
        ], 'invoice_create');
    }

    /**
     * @return array<string, mixed>
     */
    public function invoiceView(string $email, string $passwd, string $invid): array
    {
        return $this->postClient('/api/client/invoice/view', [
            'email' => $email,
            'passwd' => $passwd,
            'invid' => $invid,
        ], 'invoice_view');
    }

    /**
     * @return array<string, mixed>
     */
    public function invoicePay(string $email, string $passwd, string $invid, int $gatewayid): array
    {
        return $this->postClient('/api/client/invoice/pay', [
            'email' => $email,
            'passwd' => $passwd,
            'invid' => $invid,
            'gatewayid' => $gatewayid,
        ], 'invoice_pay');
    }

    /**
     * @return array<string, mixed>
     */
    public function accountInfo(string $email, string $passwd, ?int $serviceid = null): array
    {
        $body = [
            'email' => $email,
            'passwd' => $passwd,
        ];
        if ($serviceid !== null) {
            $body['serviceid'] = $serviceid;
        }

        return $this->postClient('/api/client/account/info', $body, 'account_info');
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceInfo(string $email, string $passwd, int $serviceid): array
    {
        return $this->postClient('/api/client/service/info', [
            'email' => $email,
            'passwd' => $passwd,
            'serviceid' => $serviceid,
        ], 'service_info');
    }

    /**
     * @return array<string, mixed>
     */
    public function serviceRenew(string $email, string $passwd, int $sid): array
    {
        return $this->postClient('/api/client/service/renew', [
            'email' => $email,
            'passwd' => $passwd,
            'sid' => $sid,
        ], 'service_renew');
    }
}
