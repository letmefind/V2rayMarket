<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
class Order extends Model
{

    protected $fillable = [
        'user_id', 'plan_id', 'status', 'expires_at',
        'payment_method', 'card_payment_receipt', 'nowpayments_payment_id', 'plisio_txn_id',
        'crypto_network', 'crypto_tx_hash', 'crypto_amount_expected', 'crypto_payment_proof',
        'config_details',
        'amount',
        'discount_amount',
        'discount_code_id',
        'renews_order_id',
        'source',
        'panel_username',
        'service_label',
        'panel_client_id',
        'xmplus_inv_id',

    ];

    protected $casts = [
        'crypto_amount_expected' => 'decimal:8',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    public function store(Plan $plan)
    {

        return view('payment.choose', ['plan' => $plan]);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($order) {
            Log::info('Order is being created', [
                'panel_username' => $order->panel_username,
                'user_id' => $order->user_id
            ]);
        });
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * نام نمایشی سرویس در لیست ربات/داشبورد (نام انتخاب‌شده توسط کاربر).
     */
    public function serviceDisplayLabel(): string
    {
        $label = trim((string) ($this->service_label ?? ''));
        if ($label !== '') {
            return $label;
        }

        $username = trim((string) ($this->panel_username ?? ''));
        if ($username !== '' && ! str_contains($username, '@')) {
            return $username;
        }

        return 'سرویس-'.$this->id;
    }

    /**
     * قبل از ذخیرهٔ ایمیل پنل در panel_username، نام انتخاب‌شده را در service_label نگه می‌دارد.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function mergePreserveServiceLabel(self $order, array $attributes): array
    {
        $incoming = $attributes['panel_username'] ?? null;
        if (! is_string($incoming) || ! str_contains($incoming, '@')) {
            return $attributes;
        }

        if (! empty($attributes['service_label']) || ! empty($order->service_label)) {
            return $attributes;
        }

        $current = trim((string) ($order->panel_username ?? ''));
        if ($current !== '' && ! str_contains($current, '@')) {
            $attributes['service_label'] = $current;
        }

        return $attributes;
    }
}
