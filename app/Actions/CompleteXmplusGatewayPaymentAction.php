<?php

namespace App\Actions;

use App\Events\OrderPaid;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\XmplusProvisioningService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * ادامهٔ سفارش XMPlus پس از انتخاب درگاه (تلگرام یا وب).
 */
final class CompleteXmplusGatewayPaymentAction
{
    /**
     * @return array{ok: bool, message: string}
     */
    public static function execute(int $orderId, int $gatewayId, string $telegramChatId): array
    {
        $settings = Setting::all()->pluck('value', 'key');
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['ok' => false, 'message' => 'نوع پنل XMPlus نیست.'];
        }

        $order = Order::with(['user', 'plan'])->find($orderId);
        if (! $order || ! $order->user || ! $order->plan) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }

        if ((string) $order->user->telegram_chat_id !== $telegramChatId) {
            return ['ok' => false, 'message' => 'این سفارش متعلق به شما نیست.'];
        }

        $cfg = $order->config_details;
        $canProceed = $order->status === 'pending'
            || ($order->status === 'paid' && ($cfg === null || $cfg === ''));

        if (! $canProceed) {
            return ['ok' => false, 'message' => 'این سفارش قبلاً تکمیل شده یا قابل پرداخت اینجا نیست.'];
        }

        $ctxKey = XmplusProvisioningService::invoiceContextCacheKey($orderId);
        $ctx = Cache::get($ctxKey);
        if (! is_array($ctx)) {
            return ['ok' => false, 'message' => 'زمان انتخاب درگاه گذشته است. با پشتیبانی تماس بگیرید یا سفارش را دوباره ثبت کنید.'];
        }

        if (! self::gatewayIdAllowed($ctx, $gatewayId)) {
            return ['ok' => false, 'message' => 'درگاه انتخاب‌شده معتبر نیست؛ صفحه را از نو باز کنید.'];
        }

        $api = XmplusProvisioningService::fromSettings($settings);

        try {
            // مشابه مسیر وب: اگر درگاه خارجی (PayPal/Stripe/...) باشد، اینجا polling نکن.
            $telegramFlow = XmplusProvisioningService::payInvoiceWithGatewayForWeb($api, $ctx, $gatewayId);
        } catch (\Throwable $e) {
            Log::channel('xmplus')->error('CompleteXmplusGatewayPayment: '.$e->getMessage(), ['order_id' => $orderId]);

            return ['ok' => false, 'message' => 'خطا: '.$e->getMessage()];
        }

        $outcome = (string) ($telegramFlow['outcome'] ?? '');
        if ($outcome === 'redirect') {
            $url = (string) ($telegramFlow['url'] ?? '');
            if ($url !== '' && $order->user?->telegram_chat_id) {
                try {
                    Telegram::setAccessToken($settings->get('telegram_bot_token'));
                    Telegram::sendMessage([
                        'chat_id' => $order->user->telegram_chat_id,
                        'text' => "🔗 لینک پرداخت درگاه:\n".$url,
                    ]);
                    Telegram::sendMessage([
                        'chat_id' => $order->user->telegram_chat_id,
                        'text' => 'بعد از پرداخت، دکمهٔ زیر را بزنید تا فعال‌سازی بررسی شود.',
                        'reply_markup' => \Telegram\Bot\Keyboard\Keyboard::make()->inline()
                            ->row([
                                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                                    'text' => '✅ پرداخت کردم، بررسی کن',
                                    'callback_data' => 'xmpgwcheck_'.$orderId,
                                ]),
                            ]),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('CompleteXmplusGatewayPayment telegram redirect msg: '.$e->getMessage());
                }
            }

            return ['ok' => true, 'message' => 'لینک پرداخت ارسال شد؛ بعد از پرداخت «بررسی کن» را بزنید.'];
        }

        if ($outcome === 'await_offsite') {
            $pay = is_array($telegramFlow['pay'] ?? null) ? $telegramFlow['pay'] : [];
            XmplusGatewayTelegram::sendInvoicePayInstructions($pay, $telegramChatId, $settings);
            $ctx['xmplus_web_await_pay'] = $pay;
            Cache::put($ctxKey, $ctx, now()->addHours(48));
            if ($order->user?->telegram_chat_id) {
                try {
                    Telegram::setAccessToken($settings->get('telegram_bot_token'));
                    Telegram::sendMessage([
                        'chat_id' => $order->user->telegram_chat_id,
                        'text' => 'بعد از پرداخت، دکمهٔ زیر را بزنید تا فعال‌سازی بررسی شود.',
                        'reply_markup' => \Telegram\Bot\Keyboard\Keyboard::make()->inline()
                            ->row([
                                \Telegram\Bot\Keyboard\Keyboard::inlineButton([
                                    'text' => '✅ پرداخت کردم، بررسی کن',
                                    'callback_data' => 'xmpgwcheck_'.$orderId,
                                ]),
                            ]),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('CompleteXmplusGatewayPayment telegram await msg: '.$e->getMessage());
                }
            }

            return ['ok' => true, 'message' => 'منتظر تکمیل پرداخت در درگاه هستیم؛ پس از پرداخت، «بررسی کن» را بزنید.'];
        }

        if ($outcome !== 'complete') {
            return ['ok' => false, 'message' => 'پاسخ غیرمنتظره از XMPlus.'];
        }

        $prov = [
            'final_config' => (string) ($telegramFlow['final_config'] ?? ''),
            'panel_username' => (string) ($telegramFlow['panel_username'] ?? ''),
            'panel_client_id' => $telegramFlow['panel_client_id'] ?? null,
        ];

        return self::persistAfterPoll($order, $ctx, $ctxKey, $prov, $settings);
    }

    /**
     * بررسی مجدد پس از پرداخت خارجی در ربات تلگرام.
     *
     * @return array{ok: bool, message: string}
     */
    public static function finalizeTelegramAfterOffsite(int $orderId, string $telegramChatId): array
    {
        $settings = Setting::all()->pluck('value', 'key');
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['ok' => false, 'message' => 'نوع پنل XMPlus نیست.'];
        }

        $order = Order::with(['user', 'plan'])->find($orderId);
        if (! $order || ! $order->user || ! $order->plan) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }
        if ((string) $order->user->telegram_chat_id !== $telegramChatId) {
            return ['ok' => false, 'message' => 'این سفارش متعلق به شما نیست.'];
        }

        $ctxKey = XmplusProvisioningService::invoiceContextCacheKey($orderId);
        $ctx = Cache::get($ctxKey);
        if (! is_array($ctx)) {
            return ['ok' => false, 'message' => 'جلسه پرداخت منقضی شده؛ دوباره روش پرداخت را انتخاب کنید.'];
        }

        $cfg = $order->config_details;
        $canProceed = $order->status === 'pending'
            || ($order->status === 'paid' && ($cfg === null || $cfg === ''));
        if (! $canProceed) {
            return ['ok' => false, 'message' => 'این سفارش قبلاً تکمیل شده است.'];
        }

        $api = XmplusProvisioningService::fromSettings($settings);
        $pollCtx = $ctx;
        unset($pollCtx['xmplus_web_await_pay'], $pollCtx['gateway_options']);

        try {
            $prov = XmplusProvisioningService::pollXmplusWebAfterOffsitePayment($api, $pollCtx);
        } catch (\Throwable $e) {
            Log::channel('xmplus')->error('finalizeTelegramAfterOffsite: '.$e->getMessage(), ['order_id' => $orderId]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $provArr = [
            'final_config' => $prov['final_config'],
            'panel_username' => $prov['panel_username'],
            'panel_client_id' => $prov['panel_client_id'],
        ];

        return self::persistAfterPoll($order, $ctx, $ctxKey, $provArr, $settings);
    }

    /**
     * پرداخت از صفحهٔ وب (بدون بررسی telegram_chat_id).
     *
     * @return array{
     *   ok: bool,
     *   message: string,
     *   redirect?: string,
     *   await_view?: bool,
     *   pay?: array<string, mixed>
     * }
     */
    public static function executeForWebUser(int $orderId, int $gatewayId, int $authUserId): array
    {
        $settings = Setting::all()->pluck('value', 'key');
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['ok' => false, 'message' => 'نوع پنل XMPlus نیست.'];
        }

        $order = Order::with(['user', 'plan'])->find($orderId);
        if (! $order || ! $order->user || ! $order->plan) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }

        if ((int) $order->user_id !== $authUserId) {
            return ['ok' => false, 'message' => 'این سفارش متعلق به شما نیست.'];
        }

        $cfg = $order->config_details;
        $canProceed = $order->status === 'pending'
            || ($order->status === 'paid' && ($cfg === null || $cfg === ''));

        if (! $canProceed) {
            return ['ok' => false, 'message' => 'این سفارش قبلاً تکمیل شده یا قابل پرداخت اینجا نیست.'];
        }

        $ctxKey = XmplusProvisioningService::invoiceContextCacheKey($orderId);
        $ctx = Cache::get($ctxKey);
        if (! is_array($ctx)) {
            return ['ok' => false, 'message' => 'زمان انتخاب درگاه گذشته است؛ صفحهٔ پرداخت را دوباره باز کنید.'];
        }

        if (! self::gatewayIdAllowed($ctx, $gatewayId)) {
            return ['ok' => false, 'message' => 'درگاه انتخاب‌شده معتبر نیست؛ صفحه را از نو باز کنید.'];
        }

        $api = XmplusProvisioningService::fromSettings($settings);

        try {
            $web = XmplusProvisioningService::payInvoiceWithGatewayForWeb($api, $ctx, $gatewayId);
        } catch (\Throwable $e) {
            Log::channel('xmplus')->error('CompleteXmplusGatewayPayment web: '.$e->getMessage(), ['order_id' => $orderId]);

            return ['ok' => false, 'message' => 'خطا: '.$e->getMessage()];
        }

        if (($web['outcome'] ?? '') === 'redirect') {
            $url = (string) ($web['url'] ?? '');
            if ($url === '') {
                return ['ok' => false, 'message' => 'لینک درگاه خالی است.'];
            }

            return [
                'ok' => true,
                'message' => 'در حال هدایت به درگاه پرداخت…',
                'redirect' => $url,
            ];
        }

        if (($web['outcome'] ?? '') === 'await_offsite') {
            $pay = is_array($web['pay'] ?? null) ? $web['pay'] : [];
            $ctx['xmplus_web_await_pay'] = $pay;
            Cache::put($ctxKey, $ctx, now()->addHours(48));

            return [
                'ok' => true,
                'message' => 'پس از اتمام پرداخت، دکمهٔ زیر را بزنید.',
                'await_view' => true,
                'pay' => $pay,
            ];
        }

        if (($web['outcome'] ?? '') !== 'complete') {
            return ['ok' => false, 'message' => 'پاسخ غیرمنتظره از XMPlus.'];
        }

        $prov = [
            'final_config' => (string) ($web['final_config'] ?? ''),
            'panel_username' => (string) ($web['panel_username'] ?? ''),
            'panel_client_id' => $web['panel_client_id'] ?? null,
        ];

        return self::persistAfterPoll($order, $ctx, $ctxKey, $prov, $settings);
    }

    /**
     * بعد از پرداخت خارجی (QR / تأیید ادمین / بازگشت از درگاه).
     *
     * @return array{ok: bool, message: string}
     */
    public static function finalizeWebAfterOffsite(int $orderId, int $authUserId): array
    {
        $settings = Setting::all()->pluck('value', 'key');
        if (($settings->get('panel_type') ?? '') !== 'xmplus') {
            return ['ok' => false, 'message' => 'نوع پنل XMPlus نیست.'];
        }

        $order = Order::with(['user', 'plan'])->find($orderId);
        if (! $order || ! $order->user || ! $order->plan) {
            return ['ok' => false, 'message' => 'سفارش یافت نشد.'];
        }
        if ((int) $order->user_id !== $authUserId) {
            return ['ok' => false, 'message' => 'دسترسی غیرمجاز.'];
        }

        $ctxKey = XmplusProvisioningService::invoiceContextCacheKey($orderId);
        $ctx = Cache::get($ctxKey);
        if (! is_array($ctx)) {
            return ['ok' => false, 'message' => 'جلسهٔ پرداخت منقضی شده؛ از صفحهٔ سفارش دوباره شروع کنید.'];
        }

        $cfg = $order->config_details;
        $canProceed = $order->status === 'pending'
            || ($order->status === 'paid' && ($cfg === null || $cfg === ''));
        if (! $canProceed) {
            return ['ok' => false, 'message' => 'این سفارش قبلاً تکمیل شده است.'];
        }

        $api = XmplusProvisioningService::fromSettings($settings);
        $pollCtx = $ctx;
        unset($pollCtx['xmplus_web_await_pay'], $pollCtx['gateway_options']);

        try {
            $prov = XmplusProvisioningService::pollXmplusWebAfterOffsitePayment($api, $pollCtx);
        } catch (\Throwable $e) {
            Log::channel('xmplus')->error('finalizeWebAfterOffsite: '.$e->getMessage(), ['order_id' => $orderId]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $provArr = [
            'final_config' => $prov['final_config'],
            'panel_username' => $prov['panel_username'],
            'panel_client_id' => $prov['panel_client_id'],
        ];

        return self::persistAfterPoll($order, $ctx, $ctxKey, $provArr, $settings);
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    protected static function gatewayIdAllowed(array $ctx, int $gatewayId): bool
    {
        if ($gatewayId <= 0) {
            return false;
        }
        $opts = $ctx['gateway_options'] ?? [];
        if (! is_array($opts) || $opts === []) {
            return true;
        }
        foreach ($opts as $o) {
            if (is_array($o) && (int) ($o['id'] ?? 0) === $gatewayId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array{final_config: string, panel_username: string, panel_client_id: ?string}  $prov
     * @return array{ok: bool, message: string}
     */
    protected static function persistAfterPoll(
        Order $order,
        array $ctx,
        string $ctxKey,
        array $prov,
        \Illuminate\Support\Collection $settings
    ): array {
        $user = $order->user;
        $plan = $order->plan;
        if (! $plan) {
            return ['ok' => false, 'message' => 'پلن سفارش یافت نشد.'];
        }

        $isRenewal = ! empty($ctx['is_renewal']);
        $originalOrder = $isRenewal && ! empty($ctx['original_order_id'])
            ? Order::find((int) $ctx['original_order_id'])
            : null;
        $walletCharged = ! empty($ctx['wallet_already_charged']);

        $newExpiresAt = $isRenewal && $originalOrder
            ? (new \DateTime($originalOrder->expires_at ?? 'now'))->modify("+{$plan->duration_days} days")
            : now()->addDays($plan->duration_days);

        $finalConfig = $prov['final_config'];
        $extraOrderAttrs = array_filter([
            'panel_username' => $prov['panel_username'],
            'panel_client_id' => $prov['panel_client_id'],
        ], fn ($v) => $v !== null && $v !== '');

        $telegramAppend = isset($ctx['credentials_message']) && is_string($ctx['credentials_message'])
            ? $ctx['credentials_message']
            : null;

        $txAmount = (float) ($order->amount > 0 ? $order->amount : $plan->price);

        try {
            DB::transaction(function () use (
                $order,
                $user,
                $plan,
                $isRenewal,
                $originalOrder,
                $walletCharged,
                $newExpiresAt,
                $finalConfig,
                $extraOrderAttrs,
                $txAmount
            ) {
                if ($isRenewal && $originalOrder) {
                    $renewPatch = array_merge([
                        'config_details' => $finalConfig,
                        'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
                    ], $extraOrderAttrs);
                    $originalOrder->update($renewPatch);
                    $user->update(['show_renewal_notification' => true]);
                    $user->notifications()->create([
                        'type' => 'service_renewed_admin',
                        'title' => 'سرویس شما تمدید شد!',
                        'message' => "تمدید سرویس {$plan->name} در XMPlus تکمیل شد. لینک اشتراک را به‌روز کنید.",
                        'link' => route('dashboard', ['tab' => 'my_services']),
                    ]);
                } else {
                    $newPatch = array_merge([
                        'config_details' => $finalConfig,
                        'expires_at' => $newExpiresAt,
                    ], $extraOrderAttrs);
                    $order->update($newPatch);
                    $user->notifications()->create([
                        'type' => 'service_activated_admin',
                        'title' => 'سرویس شما فعال شد!',
                        'message' => "خرید سرویس {$plan->name} تکمیل شد.",
                        'link' => route('dashboard', ['tab' => 'my_services']),
                    ]);
                }

                if (! $walletCharged) {
                    $order->update([
                        'status' => 'paid',
                        'payment_method' => 'xmplus_gateway',
                    ]);
                    $description = ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name} (XMPlus)";
                    Transaction::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'amount' => $txAmount,
                        'type' => 'purchase',
                        'status' => 'completed',
                        'description' => $description,
                    ]);
                    OrderPaid::dispatch($order);
                }
            });
        } catch (\Throwable $e) {
            Log::error('CompleteXmplusGatewayPayment DB: '.$e->getMessage(), ['order_id' => $order->id]);

            return ['ok' => false, 'message' => 'خطا در ذخیرهٔ سفارش. با پشتیبانی تماس بگیرید.'];
        }

        Cache::forget($ctxKey);

        if ($user->telegram_chat_id) {
            try {
                Telegram::setAccessToken($settings->get('telegram_bot_token'));
                $telegramMessage = $isRenewal
                    ? "✅ سرویس شما (*{$plan->name}*) تمدید شد.\n\nلینک جدید:\n`".$finalConfig.'`'
                    : "✅ سرویس شما (*{$plan->name}*) فعال شد.\n\n`".$finalConfig.'`';
                if ($telegramAppend) {
                    $telegramMessage .= "\n\n".$telegramAppend;
                }
                Telegram::sendMessage([
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $telegramMessage,
                    'parse_mode' => 'Markdown',
                ]);
            } catch (\Throwable $e) {
                Log::warning('CompleteXmplusGatewayPayment Telegram: '.$e->getMessage());
            }
        }

        return ['ok' => true, 'message' => 'سرویس با موفقیت فعال شد.'];
    }
}
