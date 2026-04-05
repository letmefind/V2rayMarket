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
 * ادامهٔ سفارش XMPlus پس از انتخاب درگاه توسط کاربر در تلگرام (callback xmpgw_*).
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

        $api = XmplusProvisioningService::fromSettings($settings);

        try {
            $prov = XmplusProvisioningService::payInvoiceWithGatewayAndPoll(
                $api,
                $ctx,
                $gatewayId,
                $settings,
                $telegramChatId
            );
        } catch (\Throwable $e) {
            Log::channel('xmplus')->error('CompleteXmplusGatewayPayment: '.$e->getMessage(), ['order_id' => $orderId]);

            return ['ok' => false, 'message' => 'خطا: '.$e->getMessage()];
        }

        $user = $order->user;
        $plan = $order->plan;
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
                $extraOrderAttrs
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
                    $order->update(['status' => 'paid']);
                    $description = ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name}";
                    Transaction::create([
                        'user_id' => $user->id,
                        'order_id' => $order->id,
                        'amount' => $plan->price,
                        'type' => 'purchase',
                        'status' => 'completed',
                        'description' => $description,
                    ]);
                    OrderPaid::dispatch($order);
                }
            });
        } catch (\Throwable $e) {
            Log::error('CompleteXmplusGatewayPayment DB: '.$e->getMessage(), ['order_id' => $orderId]);

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
