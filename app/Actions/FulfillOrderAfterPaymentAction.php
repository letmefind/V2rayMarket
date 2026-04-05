<?php

namespace App\Actions;

use App\Events\OrderPaid;
use App\Models\DiscountCode;
use App\Models\DiscountCodeUsage;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\MarzbanService;
use App\Services\XmplusProvisioningService;
use App\Services\XUIService;
use App\Support\XmplusGatewayTelegram;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

/**
 * تکمیل سفارش پس از تأیید پرداخت بیرونی (مثلاً Plisio) — بدون کسر از کیف پول.
 */
class FulfillOrderAfterPaymentAction
{
    public function execute(Order $order, string $paymentMethod): bool
    {
        return DB::transaction(function () use ($order, $paymentMethod) {
            /** @var Order|null $locked */
            $locked = Order::query()->lockForUpdate()->find($order->id);
            if (! $locked || $locked->status === 'paid') {
                return false;
            }

            $order = $locked->load(['user', 'plan']);

            if (! $order->plan_id) {
                $this->fulfillWalletTopUp($order, $paymentMethod);

                return true;
            }

            $this->fulfillPlanPurchase($order, $paymentMethod);

            return true;
        });
    }

    protected function fulfillWalletTopUp(Order $order, string $paymentMethod): void
    {
        $user = $order->user;
        $settings = Setting::all()->pluck('value', 'key');

        $order->update([
            'status' => 'paid',
            'payment_method' => $paymentMethod,
        ]);

        $user->increment('balance', $order->amount);

        Transaction::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => $order->amount,
            'type' => 'deposit',
            'status' => 'completed',
            'description' => 'شارژ کیف پول (Plisio)',
        ]);

        $user->notifications()->create([
            'type' => 'wallet_charged_plisio',
            'title' => 'کیف پول شما شارژ شد!',
            'message' => 'مبلغ '.number_format($order->amount).' تومان از طریق پرداخت کریپتو به کیف پول شما اضافه شد.',
            'link' => route('dashboard', ['tab' => 'order_history']),
        ]);

        $this->notifyTelegramWallet($user, $order, $settings);
    }

    protected function notifyTelegramWallet($user, Order $order, $settings): void
    {
        if (! $user->telegram_chat_id) {
            return;
        }
        $token = $settings->get('telegram_bot_token');
        if (! $token) {
            return;
        }
        try {
            Telegram::setAccessToken($token);
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => '✅ کیف پول شما به مبلغ *'.number_format($order->amount).' تومان* شارژ شد.',
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram notify wallet plisio: '.$e->getMessage());
        }
    }

    protected function fulfillPlanPurchase(Order $order, string $paymentMethod): void
    {
        $user = $order->user;
        $plan = $order->plan;
        if (! $plan) {
            throw new \RuntimeException('سفارش فاقد پلن است.');
        }

        $finalPrice = (float) $order->amount;
        $discountAmount = (float) ($order->discount_amount ?? 0);
        $originalPrice = (float) $plan->price;

        if ($order->discount_code_id && ! DiscountCodeUsage::where('order_id', $order->id)->exists()) {
            $discountCode = DiscountCode::find($order->discount_code_id);
            if ($discountCode) {
                DiscountCodeUsage::create([
                    'discount_code_id' => $discountCode->id,
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'discount_amount' => $discountAmount,
                    'original_amount' => $originalPrice,
                ]);
                $discountCode->increment('used_count');
            }
        }

        $settings = Setting::all()->pluck('value', 'key');
        $success = false;
        $finalConfig = '';
        $extraOrderAttrs = [];
        $telegramAppend = null;
        $panelType = $settings->get('panel_type');
        $isRenewal = (bool) $order->renews_order_id;

        $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;
        if ($isRenewal && ! $originalOrder) {
            throw new \RuntimeException('سفارش اصلی جهت تمدید یافت نشد.');
        }

        $uniqueUsername = $order->panel_username ?? 'user-'.$user->id.'-order-'.($isRenewal ? $originalOrder->id : $order->id);
        $newExpiresAt = $isRenewal
            ? (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days")
            : now()->addDays($plan->duration_days);

        $timestamp = $newExpiresAt->getTimestamp();

        if ($panelType === 'marzban') {
            $marzbanService = new MarzbanService(
                $settings->get('marzban_host'),
                $settings->get('marzban_sudo_username'),
                $settings->get('marzban_sudo_password'),
                $settings->get('marzban_node_hostname')
            );

            $userData = [
                'expire' => $timestamp,
                'data_limit' => $plan->volume_gb * 1073741824,
            ];

            $response = $isRenewal
                ? $marzbanService->updateUser($uniqueUsername, $userData)
                : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

            if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                $finalConfig = $marzbanService->generateSubscriptionLink($response);
                $success = true;
            }
        } elseif ($panelType === 'xui') {
            $xuiService = new XUIService(
                $settings->get('xui_host'),
                $settings->get('xui_user'),
                $settings->get('xui_pass')
            );

            $defaultInboundId = $settings->get('xui_default_inbound_id');
            if (empty($defaultInboundId)) {
                throw new \RuntimeException('تنظیمات اینباند پیش‌فرض برای X-UI یافت نشد.');
            }

            $inbound = Inbound::findByPanelInboundId($defaultInboundId);

            if (! $inbound || ! $inbound->inbound_data) {
                throw new \RuntimeException("اینباند X-UI با id «{$defaultInboundId}» در دیتابیس نیست؛ ابتدا از پنل ادمین اینباندها را با X-UI همگام‌سازی کنید.");
            }

            $inboundData = $inbound->inbound_data;

            if (! $xuiService->login()) {
                throw new \RuntimeException('خطا در لاگین به پنل X-UI.');
            }

            $clientData = [
                'email' => $uniqueUsername,
                'total' => $plan->volume_gb * 1073741824,
                'expiryTime' => $timestamp * 1000,
            ];

            if ($isRenewal) {
                $linkType = $settings->get('xui_link_type', 'single');
                $originalConfig = $originalOrder->config_details;
                $clients = $xuiService->getClients($inboundData['id']);

                if (empty($clients)) {
                    throw new \RuntimeException('هیچ کلاینتی در اینباند یافت نشد.');
                }

                $client = collect($clients)->firstWhere('email', $uniqueUsername);
                if (! $client) {
                    throw new \RuntimeException("کلاینت با ایمیل {$uniqueUsername} یافت نشد.");
                }

                $clientData['id'] = $client['id'];
                if ($linkType === 'subscription' && isset($client['subId'])) {
                    $clientData['subId'] = $client['subId'];
                }

                $response = $xuiService->updateClient($inboundData['id'], $client['id'], $clientData);
                if ($response && isset($response['success']) && $response['success']) {
                    $finalConfig = $originalConfig;
                    $success = true;
                } else {
                    $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                    throw new \RuntimeException('خطا در بروزرسانی کلاینت: '.$errorMsg);
                }
            } else {
                $response = $xuiService->addClient($inboundData['id'], $clientData);
                if ($response && isset($response['success']) && $response['success']) {
                    $linkType = $settings->get('xui_link_type', 'single');
                    if ($linkType === 'subscription') {
                        $subId = $response['generated_subId'];
                        $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                        if ($subBaseUrl && $subId) {
                            $finalConfig = $subBaseUrl.'/sub/'.$subId;
                            $success = true;
                        } else {
                            throw new \RuntimeException('خطا در ساخت لینک سابسکریپشن.');
                        }
                    } else {
                        $uuid = $response['generated_uuid'];
                        $streamSettings = $inboundData['streamSettings'] ?? [];
                        if (is_string($streamSettings)) {
                            $streamSettings = json_decode($streamSettings, true) ?? [];
                        }
                        $serverIpOrDomain = null;
                        $port = $inboundData['port'];
                        if (isset($streamSettings['externalProxy']) && is_array($streamSettings['externalProxy']) && ! empty($streamSettings['externalProxy'])) {
                            $externalProxy = $streamSettings['externalProxy'][0] ?? null;
                            if ($externalProxy && isset($externalProxy['dest'])) {
                                $serverIpOrDomain = $externalProxy['dest'];
                            }
                        }
                        if (empty($serverIpOrDomain) && ! empty($inboundData['listen'])) {
                            $serverIpOrDomain = $inboundData['listen'];
                        }
                        if (empty($serverIpOrDomain)) {
                            $parsedUrl = parse_url($settings->get('xui_host'));
                            $serverIpOrDomain = $parsedUrl['host'] ?? '';
                        }
                        $remark = $inboundData['remark'];
                        $network = $streamSettings['network'] ?? 'tcp';
                        $paramsArray = [
                            'type' => $network,
                            'encryption' => 'none',
                            'security' => $streamSettings['security'] ?? null,
                        ];
                        if ($network === 'ws' && isset($streamSettings['wsSettings'])) {
                            $paramsArray['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                            $paramsArray['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? null;
                        } elseif ($network === 'grpc' && isset($streamSettings['grpcSettings'])) {
                            $paramsArray['path'] = $streamSettings['grpcSettings']['serviceName'] ?? null;
                        } elseif (in_array($network, ['http', 'xhttp'], true) && isset($streamSettings['httpSettings'])) {
                            $httpSettings = $streamSettings['httpSettings'];
                            $paramsArray['path'] = $httpSettings['path'] ?? '/';
                            $paramsArray['host'] = $httpSettings['host'] ?? ($httpSettings['headers']['Host'] ?? null);
                            $paramsArray['mode'] = $httpSettings['mode'] ?? 'auto';
                        } elseif (in_array($network, ['http', 'xhttp'], true) && isset($streamSettings['xhttpSettings'])) {
                            $xhttpSettings = $streamSettings['xhttpSettings'];
                            $paramsArray['path'] = $xhttpSettings['path'] ?? '/';
                            $paramsArray['host'] = $xhttpSettings['host'] ?? ($xhttpSettings['headers']['Host'] ?? null);
                            $paramsArray['mode'] = $xhttpSettings['mode'] ?? 'auto';
                        }
                        if (isset($streamSettings['tlsSettings']) && ($streamSettings['security'] ?? 'none') === 'tls') {
                            $tlsSettings = $streamSettings['tlsSettings'];
                            $paramsArray['sni'] = $tlsSettings['serverName'] ?? null;
                            $paramsArray['alpn'] = is_array($tlsSettings['alpn'] ?? null)
                                ? implode(',', $tlsSettings['alpn'])
                                : ($tlsSettings['alpn'] ?? null);
                            $tlsSettingsInner = $tlsSettings['settings'] ?? [];
                            $paramsArray['fp'] = $tlsSettingsInner['fingerprint']
                                ?? $tlsSettings['fingerprint']
                                ?? $tlsSettings['fp']
                                ?? null;
                            $allowInsecure = $tlsSettingsInner['allowInsecure'] ?? $tlsSettings['allowInsecure'] ?? false;
                            if ($allowInsecure === true || $allowInsecure === '1' || $allowInsecure === 1 || $allowInsecure === 'true') {
                                $paramsArray['allowInsecure'] = '1';
                            }
                        }
                        if (isset($streamSettings['realitySettings']) && ($streamSettings['security'] ?? 'none') === 'reality') {
                            $realitySettings = $streamSettings['realitySettings'];
                            $realitySettingsInner = $realitySettings['settings'] ?? [];
                            $paramsArray['pbk'] = $realitySettingsInner['publicKey'] ?? null;
                            $paramsArray['fp'] = $realitySettingsInner['fingerprint'] ?? null;
                            $serverName = null;
                            if (! empty($realitySettingsInner['serverName'])) {
                                $serverName = $realitySettingsInner['serverName'];
                            } elseif (isset($realitySettings['serverNames'][0]) && ! empty($realitySettings['serverNames'][0])) {
                                $serverName = $realitySettings['serverNames'][0];
                            } elseif (isset($realitySettings['target']) && ! empty($realitySettings['target'])) {
                                $target = $realitySettings['target'];
                                $serverName = str_contains($target, ':') ? explode(':', $target)[0] : $target;
                            }
                            if ($serverName) {
                                $paramsArray['sni'] = $serverName;
                            }
                            $paramsArray['spx'] = $realitySettingsInner['spiderX'] ?? null;
                            if (isset($realitySettings['shortIds']) && is_array($realitySettings['shortIds']) && ! empty($realitySettings['shortIds'])) {
                                $paramsArray['sid'] = $realitySettings['shortIds'][0];
                            }
                        }
                        $params = http_build_query(array_filter($paramsArray));
                        $fullRemark = $uniqueUsername.'|'.$remark;
                        $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#".urlencode($fullRemark);
                        $success = true;
                    }
                } else {
                    $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                    throw new \RuntimeException('خطا در ساخت کاربر در پنل X-UI: '.$errorMsg);
                }
            }

            if (! $success) {
                throw new \RuntimeException('خطا در ارتباط با سرور برای فعال‌سازی سرویس.');
            }
        } elseif ($panelType === 'xmplus') {
            $result = XmplusProvisioningService::provisionPurchase(
                $settings,
                $user,
                $plan,
                $order,
                $isRenewal,
                $originalOrder
            );
            if (($result['phase'] ?? '') === 'await_gateway') {
                if (! $user->telegram_chat_id) {
                    throw new \RuntimeException('برای تکمیل پرداخت XMPlus باید حساب به ربات تلگرام متصل باشد یا در تنظیمات «شناسه درگاه پرداخت خودکار» را بگذارید.');
                }

                $order->update([
                    'status' => 'paid',
                    'payment_method' => $paymentMethod,
                    'expires_at' => $newExpiresAt,
                ]);

                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'amount' => $finalPrice,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name} ({$paymentMethod} — در انتظار درگاه XMPlus)"
                        .($discountAmount > 0 ? ' (تخفیف: '.number_format($discountAmount).' تومان)' : ''),
                ]);

                XmplusProvisioningService::markInvoiceContextWalletCharged($order->id);

                OrderPaid::dispatch($order);

                XmplusGatewayTelegram::sendGatewayPicker($order->fresh(['user', 'plan']), $settings);

                $user->notifications()->create([
                    'type' => 'xmplus_gateway_pick',
                    'title' => 'انتخاب درگاه XMPlus',
                    'message' => 'برای تکمیل سفارش #'.$order->id.' در ربات تلگرام درگاه پرداخت را انتخاب کنید.',
                    'link' => route('dashboard', ['tab' => 'my_services']),
                ]);

                return;
            }

            $finalConfig = $result['final_config'];
            $success = true;
            $extraOrderAttrs = array_filter([
                'panel_username' => $result['panel_username'],
                'panel_client_id' => $result['panel_client_id'],
            ], fn ($v) => $v !== null && $v !== '');
            $telegramAppend = $result['credentials_message'] ?? null;
        } else {
            throw new \RuntimeException('نوع پنل در تنظیمات مشخص نشده است.');
        }

        if ($isRenewal) {
            $originalOrder->update(array_merge([
                'config_details' => $finalConfig,
                'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
            ], $extraOrderAttrs));
            $user->update(['show_renewal_notification' => true]);
            $user->notifications()->create([
                'type' => 'service_renewed',
                'title' => 'سرویس شما تمدید شد!',
                'message' => "سرویس {$originalOrder->plan->name} با موفقیت تمدید شد.",
                'link' => route('dashboard', ['tab' => 'my_services']),
            ]);
        } else {
            $order->update(array_merge([
                'config_details' => $finalConfig,
                'expires_at' => $newExpiresAt,
            ], $extraOrderAttrs));
            $user->notifications()->create([
                'type' => 'service_purchased',
                'title' => 'سرویس شما فعال شد!',
                'message' => "سرویس {$plan->name} با موفقیت خریداری و فعال شد.",
                'link' => route('dashboard', ['tab' => 'my_services']),
            ]);
        }

        $order->update([
            'status' => 'paid',
            'payment_method' => $paymentMethod,
        ]);

        Transaction::create([
            'user_id' => $user->id,
            'order_id' => $order->id,
            'amount' => $finalPrice,
            'type' => 'purchase',
            'status' => 'completed',
            'description' => ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name} (Plisio)"
                .($discountAmount > 0 ? ' (تخفیف: '.number_format($discountAmount).' تومان)' : ''),
        ]);

        OrderPaid::dispatch($order);

        $this->notifyTelegramPlan($user, $order, $plan, $finalConfig, $isRenewal, $settings, $telegramAppend);
    }

    protected function notifyTelegramPlan($user, Order $order, $plan, string $finalConfig, bool $isRenewal, $settings, ?string $appendText = null): void
    {
        if (! $user->telegram_chat_id) {
            return;
        }
        $token = $settings->get('telegram_bot_token');
        if (! $token) {
            return;
        }
        try {
            Telegram::setAccessToken($token);
            $label = $isRenewal ? 'تمدید شد' : 'فعال شد';
            $text = "✅ سرویس «{$plan->name}» {$label}.\n\n{$finalConfig}";
            if ($appendText) {
                $text .= "\n\n".$appendText;
            }
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $text,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Telegram notify plan plisio: '.$e->getMessage());
        }
    }
}
