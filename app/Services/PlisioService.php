<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PlisioService
{
    protected const API_BASE = 'https://api.plisio.net/api/v1/invoices/new';

    public function __construct(
        protected Collection $settings,
    ) {}

    public static function fromDatabase(): self
    {
        return new self(Setting::all()->pluck('value', 'key'));
    }

    public function isEnabled(): bool
    {
        $enabled = filter_var($this->settings->get('plisio_enabled', false), FILTER_VALIDATE_BOOLEAN);
        $key = $this->getApiKey();

        return $enabled && $key !== '';
    }

    public function getApiKey(): string
    {
        return trim((string) $this->settings->get('plisio_api_key', ''));
    }

    /**
     * مبلغی که به Plisio به‌عنوان source_amount فرستاده می‌شود (بعد از ضریب).
     */
    public function resolveSourceAmount(Order $order): float
    {
        $base = (float) $order->amount;
        $multiplier = (float) ($this->settings->get('plisio_amount_multiplier', 10) ?: 10);

        return round($base * $multiplier, 2);
    }

    public function createInvoice(Order $order, ?string $customerEmail = null): array
    {
        if (! $this->isEnabled()) {
            throw new \RuntimeException('درگاه Plisio فعال یا پیکربندی نشده است.');
        }

        $apiKey = $this->getApiKey();
        $sourceCurrency = strtoupper(trim((string) $this->settings->get('plisio_source_currency', 'IRR')));
        $sourceAmount = $this->resolveSourceAmount($order);

        $callbackUrl = route('webhooks.plisio', [], true);
        $successUrl = route('dashboard', [], true).'?plisio=success';
        $failUrl = route('dashboard', [], true).'?plisio=cancel';

        $orderName = $order->plan
            ? 'Order #'.$order->id.' — '.$order->plan->name
            : 'Wallet charge #'.$order->id;

        $email = $customerEmail ?: $order->user?->email;
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'user'.$order->user_id.'@orders.local';
        }

        $params = [
            'api_key' => $apiKey,
            'order_number' => (string) $order->id,
            'order_name' => mb_substr($orderName, 0, 128),
            'source_currency' => $sourceCurrency,
            'source_amount' => $sourceAmount,
            'callback_url' => $callbackUrl,
            'success_invoice_url' => $successUrl,
            'fail_invoice_url' => $failUrl,
            'email' => $email,
            'return_existing' => '1',
        ];

        $allowed = trim((string) $this->settings->get('plisio_allowed_psys_cids', ''));
        if ($allowed !== '') {
            $params['allowed_psys_cids'] = $allowed;
        }

        $response = Http::timeout(30)->get(self::API_BASE, $params);

        if (! $response->successful()) {
            Log::error('Plisio HTTP error', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('خطا در ارتباط با Plisio.');
        }

        $json = $response->json();
        if (($json['status'] ?? '') !== 'success' || empty($json['data']['invoice_url'])) {
            $msg = $json['data']['message'] ?? $json['message'] ?? 'پاسخ نامعتبر از Plisio';
            Log::error('Plisio API error', ['response' => $json]);
            throw new \RuntimeException(is_string($msg) ? $msg : 'خطا در ساخت فاکتور Plisio.');
        }

        return [
            'txn_id' => (string) $json['data']['txn_id'],
            'invoice_url' => (string) $json['data']['invoice_url'],
        ];
    }

    public function verifyCallbackPayload(array $post, string $secretKey): bool
    {
        if (empty($post['verify_hash'])) {
            return false;
        }

        $verifyHash = $post['verify_hash'];
        unset($post['verify_hash']);
        ksort($post);

        if (isset($post['expire_utc'])) {
            $post['expire_utc'] = (string) $post['expire_utc'];
        }
        if (isset($post['tx_urls'])) {
            $post['tx_urls'] = html_entity_decode((string) $post['tx_urls'], ENT_QUOTES, 'UTF-8');
        }

        $postString = serialize($post);
        $checkKey = hash_hmac('sha1', $postString, $secretKey);

        return hash_equals((string) $checkKey, (string) $verifyHash);
    }
}
