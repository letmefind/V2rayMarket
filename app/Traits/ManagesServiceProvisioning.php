<?php

namespace App\Traits;

use App\Models\Order;
use App\Models\Inbound;
use App\Models\Plan;
use App\Services\MarzbanService;
use App\Services\XUIService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

trait ManagesServiceProvisioning
{
    /**
     * سرویس کاربر را در پنل مربوطه (Marzban/XUI) ایجاد یا تمدید می‌کند.
     *
     * @param string $panelType نوع پنل (marzban یا xui)
     * @param \Illuminate\Support\Collection $settings تنظیمات برنامه
     * @param Order $order سفارش
     * @param bool $isTelegramContext آیا از تلگرام فراخوانی شده؟ (برای مدیریت خطا)
     * @return array|false آرایه‌ای شامل ['config' => $config, 'expires_at' => $expires_at] در صورت موفقیت، یا false در صورت شکست
     */
    public function provisionService(string $panelType, $settings, Order $order, bool $isTelegramContext = false)
    {
        $user = $order->user;
        $plan = $order->plan;
        if (!$plan) {
            $this->handleProvisioningError("سفارش {$order->id} فاقد پلن است.", $isTelegramContext);
            return false;
        }

        $isRenewal = (bool)$order->renews_order_id;
        $originalOrder = null;

        if ($isRenewal) {
            $originalOrder = Order::find($order->renews_order_id);
            if (!$originalOrder) {
                $this->handleProvisioningError('سفارش اصلی جهت تمدید یافت نشد.', $isTelegramContext);
                return false;
            }
        }

        // نام کاربری بر اساس سفارش اصلی (در صورت تمدید) یا سفارش فعلی (در صورت خرید جدید)
        $uniqueUsername = "user-{$user->id}-order-" . ($isRenewal ? $originalOrder->id : $order->id);

        // محاسبه تاریخ انقضای جدید
        $baseDate = now();
        if ($isRenewal) {
            $baseDate = (new \DateTime($originalOrder->expires_at));
            // اگر سرویس منقضی شده، تمدید از امروز حساب شود
            if ($baseDate < now()) {
                $baseDate = now();
            }
        }

        // $newExpiresAt به یک آبجکت DateTime تبدیل می‌شود
        $newExpiresAt = $baseDate->modify("+{$plan->duration_days} days");

        $finalConfig = null;
        $success = false;

        try {
            if ($panelType === 'marzban') {
                $marzbanService = new MarzbanService($settings->get('marzban_host'), $settings->get('marzban_sudo_username'), $settings->get('marzban_sudo_password'), $settings->get('marzban_node_hostname'));

                // مطمئن شوید مدل Plan ستون data_limit_gb را دارد (در کد شما volume_gb بود، من به data_limit_gb تغییر دادم)
                $userData = ['expire' => $newExpiresAt->getTimestamp(), 'data_limit' => $plan->data_limit_gb * 1024 * 1024 * 1024];

                $response = $isRenewal
                    ? $marzbanService->updateUser($uniqueUsername, $userData)
                    : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                    $finalConfig = $marzbanService->generateSubscriptionLink($response);
                    $success = true;
                } else {
                    $error = $response['detail'] ?? 'پاسخ نامعتبر از مرزبان.';
                    $this->handleProvisioningError($error, $isTelegramContext, ['response' => $response]);
                    return false;
                }

            } elseif ($panelType === 'xui') {
                $inboundId = $settings->get('xui_default_inbound_id');
                if (!$inboundId) {
                    $this->handleProvisioningError('اینباند XUI در تنظیمات ست نشده.', $isTelegramContext); return false;
                }
                $xuiService = new XUIService($settings->get('xui_host'), $settings->get('xui_user'), $settings->get('xui_pass'));
                if (!$xuiService->login()) {
                    $this->handleProvisioningError('خطا در لاگین به پنل X-UI.', $isTelegramContext); return false;
                }
                $inbound = Inbound::find($inboundId);
                if (!$inbound || !$inbound->inbound_data) {
                    $this->handleProvisioningError('اطلاعات اینباند پیش‌فرض X-UI یافت نشد.', $isTelegramContext); return false;
                }

                $inboundData = json_decode($inbound->inbound_data, true);
                // مطمئن شوید مدل Plan ستون data_limit_gb را دارد (در کد شما volume_gb بود، من به data_limit_gb تغییر دادم)
                $clientData = ['email' => $uniqueUsername, 'total' => $plan->data_limit_gb * 1024 * 1024 * 1024, 'expiryTime' => $newExpiresAt->getTimestamp() * 1000];

                if ($isRenewal) {
                    //TODO: منطق تمدید کاربر در XUI (یافتن کاربر و آپدیت)
                    $this->handleProvisioningError('تمدید خودکار برای پنل XUI هنوز پیاده‌سازی نشده است.', $isTelegramContext);
                    return false;
                }

                $response = $xuiService->addClient($inboundData['id'], $clientData);

                if ($response && isset($response['success']) && $response['success']) {
                    $linkType = $settings->get('xui_link_type', 'single');
                    if ($linkType === 'subscription') {
                        $subId = $response['generated_subId'] ?? null;
                        $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                        if ($subBaseUrl && $subId) {
                            $finalConfig = $subBaseUrl . '/sub/' . $subId;
                            $success = true;
                        } else {
                            $this->handleProvisioningError('آدرس پایه اشتراک XUI یا ID اشتراک ست نشده.', $isTelegramContext); return false;
                        }
                    } else { // single link
                        $uuid = $response['generated_uuid'] ?? null;
                        if (!$uuid) { $this->handleProvisioningError('UUID از پنل XUI دریافت نشد.', $isTelegramContext); return false; }

                        $streamSettings = json_decode($inboundData['streamSettings'], true);
                        
                        // تعیین آدرس و پورت سرور
                        // برای آدرس: اولویت externalProxy > listen > server_address_for_link
                        // برای پورت: همیشه از پورت اینباند استفاده می‌کنیم (نه از externalProxy.port)
                        $serverAddress = null;
                        $port = $inboundData['port']; // همیشه از پورت اینباند استفاده می‌کنیم
                        
                        // بررسی externalProxy در streamSettings برای آدرس
                        if (isset($streamSettings['externalProxy']) && is_array($streamSettings['externalProxy']) && !empty($streamSettings['externalProxy'])) {
                            $externalProxy = $streamSettings['externalProxy'][0] ?? null;
                            if ($externalProxy && isset($externalProxy['dest'])) {
                                $serverAddress = $externalProxy['dest'];
                                // پورت را از externalProxy نمی‌گیریم، از inboundData['port'] استفاده می‌کنیم
                            }
                        }
                        
                        // اگر externalProxy نبود، از listen استفاده کن
                        if (empty($serverAddress) && !empty($inboundData['listen'])) {
                            $serverAddress = $inboundData['listen'];
                        }
                        
                        // اگر هنوز آدرس نداریم، از تنظیمات استفاده کن
                        if (empty($serverAddress)) {
                            $parsedUrl = parse_url($settings->get('xui_host'));
                            $serverAddress = $parsedUrl['host'];
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
                        $finalConfig = "vless://{$uuid}@{$serverAddress}:{$port}?{$params}#" . urlencode($fullRemark);
                        $success = true;
                    }
                } else {
                    $this->handleProvisioningError($response['msg'] ?? 'پاسخ نامعتبر از XUI', $isTelegramContext, ['response' => $response]);
                    return false;
                }
            }

            if ($success) {
                return ['config' => $finalConfig, 'expires_at' => $newExpiresAt];
            } else {
                $this->handleProvisioningError('موفقیت‌آمیز نبود (Success=false) اما خطایی رخ نداد.', $isTelegramContext);
                return false;
            }

        } catch (\Exception $e) {
            $this->handleProvisioningError("خطای سیستمی: " . $e->getMessage(), $isTelegramContext, ['trace' => $e->getTraceAsString()]);
            return false;
        }
    }

    /**
     * مدیریت خطاها در Trait
     */
    protected function handleProvisioningError(string $message, bool $isTelegram, array $context = [])
    {
        Log::error($message, $context);
        if (!$isTelegram) {
            // اگر در فیلامنت هستیم، نوتیفیکیشن نشان بده
            Notification::make()->title('خطا در ساخت سرویس')->body($message)->danger()->send();
        }
        // اگر در تلگرام باشیم، فقط لاگ می‌اندازد و false برمی‌گرداند تا در try/catch مدیریت شود
    }
}
