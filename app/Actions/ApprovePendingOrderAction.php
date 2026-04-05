<?php

namespace App\Actions;

use App\Events\OrderPaid;
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

final class ApprovePendingOrderAction
{
    public static function execute(Order $order): ApprovePendingOrderResult
    {
        $settings = Setting::all()->pluck('value', 'key');
        $user = $order->user;
        $plan = $order->plan;

        if (! $plan) {
            return DB::transaction(function () use ($order, $settings, $user) {
                $order->update(['status' => 'paid']);
                $user->increment('balance', $order->amount);
                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $order->amount, 'type' => 'deposit', 'status' => 'completed', 'description' => "شارژ کیف پول (تایید دستی فیش)"]);
                $user->notifications()->create([
                    'type' => 'wallet_charged_approved',
                    'title' => 'کیف پول شما شارژ شد!',
                    'message' => "مبلغ ".number_format($order->amount).' تومان با موفقیت به کیف پول شما اضافه شد.',
                    'link' => route('dashboard', ['tab' => 'order_history']),
                ]);

                if ($user->telegram_chat_id) {
                    try {
                        $telegramMessage = '✅ کیف پول شما به مبلغ *'.number_format($order->amount)." تومان* با موفقیت شارژ شد.\n\n";
                        $telegramMessage .= 'موجودی جدید شما: *'.number_format($user->fresh()->balance).' تومان*';

                        Telegram::setAccessToken($settings->get('telegram_bot_token'));
                        Telegram::sendMessage([
                            'chat_id' => $user->telegram_chat_id,
                            'text' => $telegramMessage,
                            'parse_mode' => 'Markdown',
                        ]);
                    } catch (\Exception $e) {
                        Log::error('Failed to send wallet charge notification via Telegram: '.$e->getMessage());
                    }
                }

                return ApprovePendingOrderResult::ok('کیف پول کاربر با موفقیت شارژ شد.');
            });
        }

        if ($settings->get('panel_type') === 'xmplus') {
            return self::approveXmplusPendingOrder($order, $settings, $user, $plan);
        }

        return DB::transaction(function () use ($order, $settings, $user, $plan) {
            $panelType = $settings->get('panel_type');
$success = false;
$finalConfig = '';
$extraOrderAttrs = [];
$telegramAppend = null;
$isRenewal = (bool)$order->renews_order_id;

$originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;
if ($isRenewal && !$originalOrder) {
    return ApprovePendingOrderResult::fail('خطا', 'سفارش اصلی جهت تمدید یافت نشد.');
}
$uniqueUsername = $order->panel_username ?? "user-{$user->id}-order-". ($isRenewal ? $originalOrder->id : $order->id);
  $newExpiresAt = $isRenewal
    ? (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days")
    : now()->addDays($plan->duration_days);

if ($panelType === 'marzban') {
    $marzbanService = new MarzbanService(
        (string) $settings->get('marzban_host'),
        (string) $settings->get('marzban_sudo_username'),
        (string) $settings->get('marzban_sudo_password'),
        (string) $settings->get('marzban_node_hostname')
    );

    $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->volume_gb * 1073741824];
    $response = $isRenewal ? $marzbanService->updateUser($uniqueUsername, $userData) : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

    if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
        $finalConfig = $marzbanService->generateSubscriptionLink($response);
        $success = true;
    } else {
        return ApprovePendingOrderResult::fail('خطا در ارتباط با مرزبان', $response['detail'] ?? 'پاسخ نامعتبر.');
    }
} elseif ($panelType === 'xui') {
    $xuiService = new XUIService($settings->get('xui_host'), $settings->get('xui_user'), $settings->get('xui_pass'));
    $defaultInboundId = $settings->get('xui_default_inbound_id');
    $inbound = Inbound::findByPanelInboundId($defaultInboundId);

    if (!$inbound || !$inbound->inbound_data) {
        return ApprovePendingOrderResult::fail('خطا', 'اینباند پیش‌فرض X-UI در دیتابیس نیست. از ادمین → اینباندها دکمهٔ «همگام‌سازی با X-UI» را بزنید؛ سپس در تنظیمات تم مقدار «اینباند پیش‌فرض» را روی id پنل (مثلاً 1) بگذارید.');
    }
    if (!$xuiService->login()) {
        return ApprovePendingOrderResult::fail('خطا', 'خطا در لاگین به پنل X-UI.');
    }

    $inboundData = is_string($inbound->inbound_data)
        ? json_decode($inbound->inbound_data, true)
        : $inbound->inbound_data;

    // استفاده از getTimestamp() به جای timestamp
    $clientData = ['email' => $uniqueUsername, 'total' => $plan->volume_gb * 1073741824, 'expiryTime' => $newExpiresAt->getTimestamp() * 1000];

    if ($isRenewal) {
        // ----- THIS IS THE FIXED CODE FOR RENEWAL -----
        $originalOrder = Order::find($order->renews_order_id);
        if (!$originalOrder || !$originalOrder->config_details) {
            return ApprovePendingOrderResult::fail('خطا', 'اطلاعات سرویس اصلی یافت نشد.');
        }

        $linkType = $settings->get('xui_link_type', 'single');
        $originalConfig = $originalOrder->config_details;
        $clientId = null;
        $subId = null;

        if ($linkType === 'subscription') {
            preg_match('/\/sub\/([a-zA-Z0-9]+)/', $originalConfig, $matches);
            $subId = $matches[1] ?? null;

            if (!$subId) {
                return ApprovePendingOrderResult::fail('خطا', 'شناسه اشتراک (subId) در کانفیگ قبلی یافت نشد.');
            }

            $clientData['subId'] = $subId;
            $clients = $xuiService->getClients($inboundData['id']);

            if (!empty($clients)) {
                $client = collect($clients)->firstWhere('subId', $subId);
                if (!$client) {
                    $client = collect($clients)->firstWhere('email', $uniqueUsername);
                }
                $clientId = $client['id'] ?? null;
            }

            if (!$clientId) {
                Log::warning('Client not found, attempting to create new', [
                    'inbound_id' => $inboundData['id'],
                    'email' => $uniqueUsername,
                    'subId' => $subId,
                ]);

                $addResponse = $xuiService->addClient($inboundData['id'], array_merge($clientData, ['subId' => $subId]));

                if ($addResponse && isset($addResponse['success']) && $addResponse['success']) {
                    $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                    $newSubId = $addResponse['generated_subId'];
                    if ($subBaseUrl && $newSubId) {
                        $finalConfig = $subBaseUrl . '/sub/' . $newSubId;
                        $success = true;
                    } else {
                        return ApprovePendingOrderResult::fail('خطا', 'خطا در ساخت لینک سابسکریپشن جدید.');
                    }
                } else {
                    $errorMsg = $addResponse['msg'] ?? 'خطای نامشخص';

                    if (strpos($errorMsg, 'Duplicate email') !== false) {
                        Log::critical('CRITICAL: getClients returned empty but client exists!', [
                            'inbound_id' => $inboundData['id'],
                            'email' => $uniqueUsername,
                            'subId' => $subId,
                        ]);
                        return ApprovePendingOrderResult::fail('خطای سیستمی', 'سرویس X-UI به درستی کار نمی‌کند.');
                    }

                    return ApprovePendingOrderResult::fail('خطا', 'خطا در ساخت کلاینت: ' . $errorMsg);
                }
            } else {
                $clientData['id'] = $clientId;
                $response = $xuiService->updateClient($inboundData['id'], $clientId, $clientData);

                if ($response && isset($response['success']) && $response['success']) {
                    $finalConfig = $originalConfig;
                    $success = true;
                } else {
                    return ApprovePendingOrderResult::fail('خطا', 'خطا در بروزرسانی کلاینت: ' . ($response['msg'] ?? 'خطای نامشخص'));
                }
            }
        } else {
            // منطق برای لینک single
            preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $originalConfig, $matches);
            $clientId = $matches[1] ?? null;

            if (!$clientId) {
                return ApprovePendingOrderResult::fail('خطا', 'UUID در کانفیگ یافت نشد.');
            }

            $clientData['id'] = $clientId;
            $clients = $xuiService->getClients($inboundData['id']);

            if (!empty($clients)) {
                $client = collect($clients)->firstWhere('id', $clientId);
                if (!$client) {
                    $client = collect($clients)->firstWhere('email', $uniqueUsername);
                }
            }

            if (empty($clients) || !$client) {
                $addResponse = $xuiService->addClient($inboundData['id'], $clientData);

                if ($addResponse && isset($addResponse['success']) && $addResponse['success']) {
                    $uuid = $addResponse['generated_uuid'];
                    $streamSettings = $inboundData['streamSettings'] ?? [];
                    if (is_string($streamSettings)) {
                        $streamSettings = json_decode($streamSettings, true) ?? [];
                    }

                    $parsedUrl = parse_url($settings->get('xui_host'));
                    $serverIpOrDomain = !empty($inboundData['listen']) ? $inboundData['listen'] : $parsedUrl['host'];
                    $port = $inboundData['port'];
                    $remark = $inboundData['remark'];

                    $paramsArray = [
                        'type' => $streamSettings['network'] ?? null,
                        'security' => $streamSettings['security'] ?? null,
                        'path' => $streamSettings['wsSettings']['path'] ?? ($streamSettings['grpcSettings']['serviceName'] ?? null),
                        'sni' => $streamSettings['tlsSettings']['serverName'] ?? null,
                        'host' => $streamSettings['wsSettings']['headers']['Host'] ?? null
                    ];
                    
                    // افزودن DNS Resolver (DoH/DoT)
                    $dnsDomain = $settings->get('dns_resolver_domain');
                    $dnsType = $settings->get('dns_resolver_type', 'doh');
                    if ($dnsDomain) {
                        if ($dnsType === 'doh') {
                            $paramsArray['doh'] = "https://{$dnsDomain}/dns-query";
                        } elseif ($dnsType === 'dot') {
                            $paramsArray['dot'] = $dnsDomain;
                        }
                    }

                    $params = http_build_query(array_filter($paramsArray));
                    $fullRemark = $uniqueUsername . '|' . $remark;
                    $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
                    $success = true;
                } else {
                    return ApprovePendingOrderResult::fail('خطا', 'خطا در ساخت کلاینت: ' . ($addResponse['msg'] ?? 'خطای نامشخص'));
                }
            } else {
                $response = $xuiService->updateClient($inboundData['id'], $clientId, $clientData);

                if ($response && isset($response['success']) && $response['success']) {
                    $finalConfig = $originalConfig;
                    $success = true;
                } else {
                    return ApprovePendingOrderResult::fail('خطا', 'خطا در بروزرسانی کلاینت: ' . ($response['msg'] ?? 'خطای نامشخص'));
                }
            }
        }
        // ----- END OF FIXED CODE -----
    } else {
        // سفارش جدید - منطق قبلی برای X-UI
        $response = $xuiService->addClient($inboundData['id'], $clientData);

        if ($response && isset($response['success']) && $response['success']) {
            $linkType = $settings->get('xui_link_type', 'single');
            if ($linkType === 'subscription') {
                $subId = $response['generated_subId'];
                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                if ($subBaseUrl && $subId) {
                    $finalConfig = $subBaseUrl . '/sub/' . $subId;
                    $success = true;
                }
            } else {
                $uuid = $response['generated_uuid'];
                $streamSettings = json_decode($inboundData['streamSettings'], true);
                
                // تعیین آدرس و پورت سرور
                // برای آدرس: اولویت externalProxy > listen > server_address_for_link
                // برای پورت: همیشه از پورت اینباند استفاده می‌کنیم (نه از externalProxy.port)
                $serverIpOrDomain = null;
                $port = $inboundData['port']; // همیشه از پورت اینباند استفاده می‌کنیم
                
                // بررسی externalProxy در streamSettings برای آدرس
                if (isset($streamSettings['externalProxy']) && is_array($streamSettings['externalProxy']) && !empty($streamSettings['externalProxy'])) {
                    $externalProxy = $streamSettings['externalProxy'][0] ?? null;
                    if ($externalProxy && isset($externalProxy['dest'])) {
                        $serverIpOrDomain = $externalProxy['dest'];
                        // پورت را از externalProxy نمی‌گیریم، از inboundData['port'] استفاده می‌کنیم
                    }
                }
                
                // اگر externalProxy نبود، از listen استفاده کن
                if (empty($serverIpOrDomain) && !empty($inboundData['listen'])) {
                    $serverIpOrDomain = $inboundData['listen'];
                }
                
                // اگر هنوز آدرس نداریم، از تنظیمات استفاده کن
                if (empty($serverIpOrDomain)) {
                    $parsedUrl = parse_url($settings->get('xui_host'));
                    $serverIpOrDomain = $parsedUrl['host'];
                }
                
                $remark = $inboundData['remark'];
                
                $network = $streamSettings['network'] ?? 'tcp';
                $paramsArray = [
                    'type' => $network,
                    'encryption' => 'none', // برای vless protocol همیشه none است
                    'security' => $streamSettings['security'] ?? null,
                ];
                
                // استخراج پارامترها بر اساس نوع شبکه
                if ($network === 'ws' && isset($streamSettings['wsSettings'])) {
                    $paramsArray['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                    $paramsArray['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? null;
                } elseif ($network === 'grpc' && isset($streamSettings['grpcSettings'])) {
                    $paramsArray['path'] = $streamSettings['grpcSettings']['serviceName'] ?? null;
                } elseif (in_array($network, ['http', 'xhttp']) && isset($streamSettings['httpSettings'])) {
                    $httpSettings = $streamSettings['httpSettings'];
                    $paramsArray['path'] = $httpSettings['path'] ?? '/';
                    $paramsArray['host'] = $httpSettings['host'] ?? ($httpSettings['headers']['Host'] ?? null);
                    $paramsArray['mode'] = $httpSettings['mode'] ?? 'auto';
                } elseif (in_array($network, ['http', 'xhttp']) && isset($streamSettings['xhttpSettings'])) {
                    $xhttpSettings = $streamSettings['xhttpSettings'];
                    $paramsArray['path'] = $xhttpSettings['path'] ?? '/';
                    $paramsArray['host'] = $xhttpSettings['host'] ?? ($xhttpSettings['headers']['Host'] ?? null);
                    $paramsArray['mode'] = $xhttpSettings['mode'] ?? 'auto';
                }
                
                // استخراج پارامترهای TLS
                if (isset($streamSettings['tlsSettings']) && ($streamSettings['security'] ?? 'none') === 'tls') {
                    $tlsSettings = $streamSettings['tlsSettings'];
                    
                    $paramsArray['sni'] = $tlsSettings['serverName'] ?? null;
                    
                    // alpn
                    $paramsArray['alpn'] = is_array($tlsSettings['alpn'] ?? null) 
                        ? implode(',', $tlsSettings['alpn']) 
                        : ($tlsSettings['alpn'] ?? null);
                    
                    // fingerprint و allowInsecure ممکن است در tlsSettings.settings باشند
                    $tlsSettingsInner = $tlsSettings['settings'] ?? [];
                    
                    // fingerprint: اول از settings، بعد از tlsSettings مستقیم
                    $paramsArray['fp'] = $tlsSettingsInner['fingerprint'] 
                        ?? $tlsSettings['fingerprint'] 
                        ?? $tlsSettings['fp'] 
                        ?? null;
                    
                    // allowInsecure: اول از settings، بعد از tlsSettings مستقیم
                    $allowInsecure = $tlsSettingsInner['allowInsecure'] 
                        ?? $tlsSettings['allowInsecure'] 
                        ?? false;
                    
                    if ($allowInsecure === true || $allowInsecure === '1' || $allowInsecure === 1 || $allowInsecure === 'true') {
                        $paramsArray['allowInsecure'] = '1';
                    }
                }
                
                // استخراج پارامترهای Reality
                if (isset($streamSettings['realitySettings']) && ($streamSettings['security'] ?? 'none') === 'reality') {
                    $realitySettings = $streamSettings['realitySettings'];
                    $realitySettingsInner = $realitySettings['settings'] ?? [];
                    
                    // publicKey از settings
                    $paramsArray['pbk'] = $realitySettingsInner['publicKey'] ?? null;
                    
                    // fingerprint از settings
                    $paramsArray['fp'] = $realitySettingsInner['fingerprint'] ?? null;
                    
                    // serverName: اول از settings (اگر خالی نباشد)، بعد از serverNames، بعد از target
                    $serverName = null;
                    if (!empty($realitySettingsInner['serverName'])) {
                        $serverName = $realitySettingsInner['serverName'];
                    } elseif (isset($realitySettings['serverNames'][0]) && !empty($realitySettings['serverNames'][0])) {
                        $serverName = $realitySettings['serverNames'][0];
                    } elseif (isset($realitySettings['target']) && !empty($realitySettings['target'])) {
                        // استخراج hostname از target (مثلاً "www.speedtest.net:443" -> "www.speedtest.net")
                        $target = $realitySettings['target'];
                        if (strpos($target, ':') !== false) {
                            $serverName = explode(':', $target)[0];
                        } else {
                            $serverName = $target;
                        }
                    }
                    if ($serverName) {
                        $paramsArray['sni'] = $serverName;
                    }
                    
                    // spiderX از settings
                    $paramsArray['spx'] = $realitySettingsInner['spiderX'] ?? null;
                    
                    // shortId از shortIds (اولین مورد)
                    if (isset($realitySettings['shortIds']) && is_array($realitySettings['shortIds']) && !empty($realitySettings['shortIds'])) {
                        $paramsArray['sid'] = $realitySettings['shortIds'][0];
                    }
                }
                
                $params = http_build_query(array_filter($paramsArray));
                $fullRemark = $uniqueUsername . '|' . $remark;
                $finalConfig = "vless://{$uuid}@{$serverIpOrDomain}:{$port}?{$params}#" . urlencode($fullRemark);
                $success = true;
            }
        } else {
            $errorMsg = $response['msg'] ?? 'پاسخ نامعتبر';
            return ApprovePendingOrderResult::fail('خطا', 'خطا در ساخت کاربر در پنل سنایی: ' . $errorMsg);
        }
    }
} else {
    $user->notifications()->create([
        'type' => 'panel_type_error_admin',
        'title' => 'خطا در فعال‌سازی سرویس!',
        'message' => "نوع پنل در تنظیمات سیستم به درستی مشخص نشده است. لطفاً به پشتیبانی اطلاع دهید.",
        'link' => route('dashboard', ['tab' => 'support']),
    ]);
    return ApprovePendingOrderResult::fail('خطا', 'نوع پنل در تنظیمات مشخص نشده است.');
}

if ($success) {
    if ($isRenewal) {
        $renewPatch = [
            'config_details' => $finalConfig,
            'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
        ];
        if ($panelType === 'xmplus') {
            $renewPatch = array_merge($renewPatch, $extraOrderAttrs);
        } else {
            $renewPatch['panel_username'] = $uniqueUsername;
        }
        $originalOrder->update($renewPatch);

        $user->update(['show_renewal_notification' => true]);

        $user->notifications()->create([
            'type' => 'service_renewed_admin',
            'title' => 'سرویس شما تمدید شد!',
            'message' => "تمدید سرویس {$originalOrder->plan->name} توسط مدیر تایید و فعال شد. لطفاً لینک اشتراک خود را به‌روزرسانی کنید.",
            'link' => route('dashboard', ['tab' => 'my_services']),
        ]);
    } else {
        $newPatch = [
            'config_details' => $finalConfig,
            'expires_at' => $newExpiresAt,
        ];
        if ($panelType === 'xmplus') {
            $newPatch = array_merge($newPatch, $extraOrderAttrs);
        } else {
            $newPatch['panel_username'] = $uniqueUsername;
        }
        $order->update($newPatch);
        $user->notifications()->create([
            'type' => 'service_activated_admin',
            'title' => 'سرویس شما فعال شد!',
            'message' => "خرید سرویس {$plan->name} توسط مدیر تایید و فعال شد.",
            'link' => route('dashboard', ['tab' => 'my_services']),
        ]);
    }

    $order->update(['status' => 'paid']);
    $description = ($isRenewal ? "تمدید سرویس" : "خرید سرویس")." {$plan->name}";
    Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $plan->price, 'type' => 'purchase', 'status' => 'completed', 'description' => $description]);
    OrderPaid::dispatch($order);

    if ($user->telegram_chat_id) {
        try {
            $telegramMessage = $isRenewal
                ? "✅ سرویس شما (*{$plan->name}*) با موفقیت تمدید شد.\n\n❗️*نکته مهم:* لینک اشتراک شما تغییر کرده است. لطفاً لینک جدید زیر را کپی و در نرم‌افزار خود آپدیت کنید:\n\n`" . $finalConfig . "`"
                : "✅ سرویس شما (*{$plan->name}*) با موفقیت فعال شد.\n\nاطلاعات کانفیگ شما:\n`" . $finalConfig . "`\n\nمی‌توانید لینک بالا را کپی کرده و در نرم‌افزار خود import کنید.";
            if ($telegramAppend) {
                $telegramMessage .= "\n\n".$telegramAppend;
            }
            Telegram::setAccessToken($settings->get('telegram_bot_token'));
            Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $telegramMessage, 'parse_mode' => 'Markdown']);
        } catch (\Exception $e) {
            Log::error('Failed to send Telegram notification: '.$e->getMessage());
        }
    }
    return ApprovePendingOrderResult::ok('عملیات با موفقیت انجام شد.');
}

return ApprovePendingOrderResult::fail('توجه', 'سفارش فعال نشد؛ پنل یا تنظیمات را بررسی کنید.');
        });
    }

    private static function approveXmplusPendingOrder(Order $order, $settings, $user, $plan): ApprovePendingOrderResult
    {
        $isRenewal = (bool) $order->renews_order_id;
        $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;
        if ($isRenewal && ! $originalOrder) {
            return ApprovePendingOrderResult::fail('خطا', 'سفارش اصلی جهت تمدید یافت نشد.');
        }

        $pending = Order::query()->whereKey($order->id)->where('status', 'pending')->first();
        if (! $pending) {
            return ApprovePendingOrderResult::fail('توجه', 'این سفارش در وضعیت انتظار تأیید نیست.');
        }

        try {
            $result = XmplusProvisioningService::provisionPurchase(
                $settings,
                $user,
                $plan,
                $pending,
                $isRenewal,
                $originalOrder,
                true
            );
        } catch (\Throwable $e) {
            Log::channel('xmplus')->error('ApprovePendingOrder XMPlus: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);

            return ApprovePendingOrderResult::fail('XMPlus', $e->getMessage());
        }

        if (($result['phase'] ?? '') === 'await_gateway') {
            XmplusGatewayTelegram::sendGatewayPicker($pending->fresh(['user']), $settings);

            return ApprovePendingOrderResult::ok(
                'فاکتور XMPlus ایجاد شد؛ درگاه‌های پرداخت برای کاربر در ربات ارسال شد.',
                null,
                'xmplus_gateway'
            );
        }

        $finalConfig = $result['final_config'];
        $extraOrderAttrs = array_filter([
            'panel_username' => $result['panel_username'],
            'panel_client_id' => $result['panel_client_id'],
        ], fn ($v) => $v !== null && $v !== '');
        $telegramAppend = $result['credentials_message'] ?? null;

        $newExpiresAt = $isRenewal
            ? (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days")
            : now()->addDays($plan->duration_days);

        return DB::transaction(function () use ($order, $plan, $user, $settings, $finalConfig, $isRenewal, $originalOrder, $extraOrderAttrs, $telegramAppend, $newExpiresAt) {
            $locked = Order::whereKey($order->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'pending') {
                return ApprovePendingOrderResult::fail('توجه', 'وضعیت سفارش قبلاً تغییر کرده است.');
            }

            if ($isRenewal) {
                $renewPatch = array_merge([
                    'config_details' => $finalConfig,
                    'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
                ], $extraOrderAttrs);
                $originalOrder->update($renewPatch);

                $user->update(['show_renewal_notification' => true]);

                $user->notifications()->create([
                    'type' => 'service_renewed_admin',
                    'title' => 'سرویس شما تمدید شد!',
                    'message' => "تمدید سرویس {$originalOrder->plan->name} توسط مدیر تایید و فعال شد. لطفاً لینک اشتراک خود را به‌روزرسانی کنید.",
                    'link' => route('dashboard', ['tab' => 'my_services']),
                ]);
            } else {
                $locked->update(array_merge([
                    'config_details' => $finalConfig,
                    'expires_at' => $newExpiresAt,
                ], $extraOrderAttrs));
                $user->notifications()->create([
                    'type' => 'service_activated_admin',
                    'title' => 'سرویس شما فعال شد!',
                    'message' => "خرید سرویس {$plan->name} توسط مدیر تایید و فعال شد.",
                    'link' => route('dashboard', ['tab' => 'my_services']),
                ]);
            }

            $locked->update(['status' => 'paid']);
            $description = ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name}";
            Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $plan->price, 'type' => 'purchase', 'status' => 'completed', 'description' => $description]);
            OrderPaid::dispatch($locked);

            if ($user->telegram_chat_id) {
                try {
                    $telegramMessage = $isRenewal
                        ? "✅ سرویس شما (*{$plan->name}*) با موفقیت تمدید شد.\n\n❗️*نکته مهم:* لینک اشتراک شما تغییر کرده است. لطفاً لینک جدید زیر را کپی و در نرم‌افزار خود آپدیت کنید:\n\n`".$finalConfig.'`'
                        : "✅ سرویس شما (*{$plan->name}*) با موفقیت فعال شد.\n\nاطلاعات کانفیگ شما:\n`".$finalConfig."`\n\nمی‌توانید لینک بالا را کپی کرده و در نرم‌افزار خود import کنید.";
                    if ($telegramAppend) {
                        $telegramMessage .= "\n\n".$telegramAppend;
                    }
                    Telegram::setAccessToken($settings->get('telegram_bot_token'));
                    Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $telegramMessage, 'parse_mode' => 'Markdown']);
                } catch (\Exception $e) {
                    Log::error('Failed to send Telegram notification: '.$e->getMessage());
                }
            }

            return ApprovePendingOrderResult::ok('عملیات با موفقیت انجام شد.');
        });
    }
}
