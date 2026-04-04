<?php

namespace App\Filament\Resources;

use App\Events\OrderPaid;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\Notification as UserNotification;
use App\Services\MarzbanService;
use App\Services\XUIService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Telegram\Bot\Laravel\Facades\Telegram;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'سفارشات';
    protected static ?string $modelLabel = 'سفارش';
    protected static ?string $pluralModelLabel = 'سفارشات';
    protected static ?string $navigationGroup = 'مدیریت سفارشات';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('user_id')->relationship('user', 'name')->label('کاربر')->disabled(),
                Forms\Components\Select::make('plan_id')->relationship('plan', 'name')->label('پلن')->disabled(),
                Forms\Components\Select::make('status')->label('وضعیت سفارش')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده'])->required(),
                Forms\Components\Textarea::make('config_details')->label('اطلاعات کانفیگ سرویس')->rows(10),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('card_payment_receipt')->label('رسید')->disk('public')->toggleable()->size(60)->circular()->url(fn (Order $record): ?string => $record->card_payment_receipt ? Storage::disk('public')->url($record->card_payment_receipt) : null)->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('user.name')->label('کاربر')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('plan.name')->label('پلن / آیتم')->default(fn (Order $record): string => $record->plan_id ? $record->plan->name : "شارژ کیف پول")->description(function (Order $record): string {
                    if ($record->renews_order_id) return " (تمدید سفارش #" . $record->renews_order_id . ")";
                    if (!$record->plan_id) return number_format($record->amount) . ' تومان';
                    return '';
                })->color(fn(Order $record) => $record->renews_order_id ? 'primary' : 'gray'),
                IconColumn::make('source')->label('منبع')->icon(fn (?string $state): string => match ($state) { 'web' => 'heroicon-o-globe-alt', 'telegram' => 'heroicon-o-paper-airplane', default => 'heroicon-o-question-mark-circle' })->color(fn (?string $state): string => match ($state) { 'web' => 'primary', 'telegram' => 'info', default => 'gray' }),
                Tables\Columns\TextColumn::make('payment_method')->label('روش پرداخت')->toggleable(isToggledHiddenByDefault: true)->formatStateUsing(fn (?string $state): string => match ($state) {
                    'manual_crypto' => 'USDT/USDC دستی',
                    'card' => 'کارت',
                    'wallet' => 'کیف پول',
                    'plisio' => 'Plisio',
                    default => $state ?? '—',
                }),
                Tables\Columns\TextColumn::make('crypto_network')->label('شبکه کریپتو')->toggleable(isToggledHiddenByDefault: true)->formatStateUsing(function (?string $state): string {
                    if (! $state) {
                        return '—';
                    }
                    return match ($state) {
                        'usdt_erc20' => 'USDT ERC20',
                        'usdt_bep20' => 'USDT BEP20',
                        'usdc_erc20' => 'USDC ERC20',
                        default => $state,
                    };
                }),
                Tables\Columns\TextColumn::make('crypto_amount_expected')->label('مقدار ارز')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('crypto_tx_hash')->label('TxID')->limit(24)->toggleable(isToggledHiddenByDefault: true),
                ImageColumn::make('crypto_payment_proof')->label('اثبات کریپتو')->disk('public')->toggleable(isToggledHiddenByDefault: true)->size(60)->circular()->url(fn (Order $record): ?string => $record->crypto_payment_proof ? Storage::disk('public')->url($record->crypto_payment_proof) : null)->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('status')->label('وضعیت')->badge()->color(fn (string $state): string => match ($state) { 'pending' => 'warning', 'paid' => 'success', 'expired' => 'danger', default => 'gray' })->formatStateUsing(fn (string $state): string => match ($state) { 'pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده', default => $state }),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ سفارش')->dateTime('Y-m-d')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->label('تاریخ انقضا')->dateTime('Y-m-d')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('وضعیت')->options(['pending' => 'در انتظار پرداخت', 'paid' => 'پرداخت شده', 'expired' => 'منقضی شده']),
                Tables\Filters\SelectFilter::make('source')->label('منبع')->options(['web' => 'وب‌سایت', 'telegram' => 'تلگرام']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Action::make('approve')->label('تایید و اجرا')->icon('heroicon-o-check-circle')->color('success')->requiresConfirmation()->modalHeading('تایید پرداخت سفارش')->modalDescription('آیا از تایید این پرداخت اطمینان دارید؟')->visible(fn (Order $order): bool => $order->status === 'pending')
                    ->action(function (Order $order) {
                        DB::transaction(function () use ($order) {
                            $settings = Setting::all()->pluck('value', 'key');
                            $user = $order->user;
                            $plan = $order->plan;

                            if (!$plan) {
                                $order->update(['status' => 'paid']);
                                $user->increment('balance', $order->amount);
                                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $order->amount, 'type' => 'deposit', 'status' => 'completed', 'description' => "شارژ کیف پول (تایید دستی فیش)"]);
                                $user->notifications()->create([
                                    'type' => 'wallet_charged_approved',
                                    'title' => 'کیف پول شما شارژ شد!',
                                    'message' => "مبلغ " . number_format($order->amount) . " تومان با موفقیت به کیف پول شما اضافه شد.",
                                    'link' => route('dashboard', ['tab' => 'order_history']),
                                ]);

                                Notification::make()->title('کیف پول کاربر با موفقیت شارژ شد.')->success()->send();

                                if ($user->telegram_chat_id) {
                                    try {
                                        $telegramMessage = "✅ کیف پول شما به مبلغ *" . number_format($order->amount) . " تومان* با موفقیت شارژ شد.\n\n";
                                        $telegramMessage .= "موجودی جدید شما: *" . number_format($user->fresh()->balance) . " تومان*";

                                        Telegram::setAccessToken($settings->get('telegram_bot_token'));
                                        Telegram::sendMessage([
                                            'chat_id' => $user->telegram_chat_id,
                                            'text' => $telegramMessage,
                                            'parse_mode' => 'Markdown'
                                        ]);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send wallet charge notification via Telegram: ' . $e->getMessage());
                                    }
                                }

                                return;
                            }

                            $panelType = $settings->get('panel_type');
                            $success = false;
                            $finalConfig = '';
                            $isRenewal = (bool)$order->renews_order_id;

                            $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;
                            if ($isRenewal && !$originalOrder) {
                                Notification::make()->title('خطا')->body('سفارش اصلی جهت تمدید یافت نشد.')->danger()->send();
                                return;
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
                                    Notification::make()->title('خطا در ارتباط با مرزبان')->body($response['detail'] ?? 'پاسخ نامعتبر.')->danger()->send();
                                    return;
                                }
                            } elseif ($panelType === 'xui') {
                                $xuiService = new XUIService($settings->get('xui_host'), $settings->get('xui_user'), $settings->get('xui_pass'));
                                $defaultInboundId = $settings->get('xui_default_inbound_id');
                                $inbound = Inbound::where('inbound_data->id', $defaultInboundId)->first();

                                if (!$inbound || !$inbound->inbound_data) {
                                    Notification::make()->title('خطا')->body('اطلاعات اینباند پیش‌فرض برای X-UI یافت نشد.')->danger()->send();
                                    return;
                                }
                                if (!$xuiService->login()) {
                                    Notification::make()->title('خطا')->body('خطا در لاگین به پنل X-UI.')->danger()->send();
                                    return;
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
                                        Notification::make()->title('خطا')->body('اطلاعات سرویس اصلی یافت نشد.')->danger()->send();
                                        return;
                                    }

                                    $linkType = $settings->get('xui_link_type', 'single');
                                    $originalConfig = $originalOrder->config_details;
                                    $clientId = null;
                                    $subId = null;

                                    if ($linkType === 'subscription') {
                                        preg_match('/\/sub\/([a-zA-Z0-9]+)/', $originalConfig, $matches);
                                        $subId = $matches[1] ?? null;

                                        if (!$subId) {
                                            Notification::make()->title('خطا')->body('شناسه اشتراک (subId) در کانفیگ قبلی یافت نشد.')->danger()->send();
                                            return;
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
                                                    Notification::make()->title('خطا')->body('خطا در ساخت لینک سابسکریپشن جدید.')->danger()->send();
                                                    return;
                                                }
                                            } else {
                                                $errorMsg = $addResponse['msg'] ?? 'خطای نامشخص';

                                                if (strpos($errorMsg, 'Duplicate email') !== false) {
                                                    Log::critical('CRITICAL: getClients returned empty but client exists!', [
                                                        'inbound_id' => $inboundData['id'],
                                                        'email' => $uniqueUsername,
                                                        'subId' => $subId,
                                                    ]);
                                                    Notification::make()->title('خطای سیستمی')->body('سرویس X-UI به درستی کار نمی‌کند.')->danger()->send();
                                                    return;
                                                }

                                                Notification::make()->title('خطا')->body('خطا در ساخت کلاینت: ' . $errorMsg)->danger()->send();
                                                return;
                                            }
                                        } else {
                                            $clientData['id'] = $clientId;
                                            $response = $xuiService->updateClient($inboundData['id'], $clientId, $clientData);

                                            if ($response && isset($response['success']) && $response['success']) {
                                                $finalConfig = $originalConfig;
                                                $success = true;
                                            } else {
                                                Notification::make()->title('خطا')->body('خطا در بروزرسانی کلاینت: ' . ($response['msg'] ?? 'خطای نامشخص'))->danger()->send();
                                                return;
                                            }
                                        }
                                    } else {
                                        // منطق برای لینک single
                                        preg_match('/([a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})/i', $originalConfig, $matches);
                                        $clientId = $matches[1] ?? null;

                                        if (!$clientId) {
                                            Notification::make()->title('خطا')->body('UUID در کانفیگ یافت نشد.')->danger()->send();
                                            return;
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
                                                Notification::make()->title('خطا')->body('خطا در ساخت کلاینت: ' . ($addResponse['msg'] ?? 'خطای نامشخص'))->danger()->send();
                                                return;
                                            }
                                        } else {
                                            $response = $xuiService->updateClient($inboundData['id'], $clientId, $clientData);

                                            if ($response && isset($response['success']) && $response['success']) {
                                                $finalConfig = $originalConfig;
                                                $success = true;
                                            } else {
                                                Notification::make()->title('خطا')->body('خطا در بروزرسانی کلاینت: ' . ($response['msg'] ?? 'خطای نامشخص'))->danger()->send();
                                                return;
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
                                        Notification::make()->title('خطا')->body('خطا در ساخت کاربر در پنل سنایی: ' . $errorMsg)->danger()->send();
                                        return;
                                    }
                                }
                            } else {
                                Notification::make()->title('خطا')->body('نوع پنل در تنظیمات مشخص نشده است.')->danger()->send();

                                $user->notifications()->create([
                                    'type' => 'panel_type_error_admin',
                                    'title' => 'خطا در فعال‌سازی سرویس!',
                                    'message' => "نوع پنل در تنظیمات سیستم به درستی مشخص نشده است. لطفاً به پشتیبانی اطلاع دهید.",
                                    'link' => route('dashboard', ['tab' => 'support']),
                                ]);
                                return;
                            }

                            if ($success) {
                                if($isRenewal) {
                                    $originalOrder->update([
                                        'config_details' => $finalConfig,
                                        'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
                                        'panel_username' => $uniqueUsername
                                    ]);

                                    $user->update(['show_renewal_notification' => true]);

                                    $user->notifications()->create([
                                        'type' => 'service_renewed_admin',
                                        'title' => 'سرویس شما تمدید شد!',
                                        'message' => "تمدید سرویس {$originalOrder->plan->name} توسط مدیر تایید و فعال شد. لطفاً لینک اشتراک خود را به‌روزرسانی کنید.",
                                        'link' => route('dashboard', ['tab' => 'my_services']),
                                    ]);
                                } else {
                                    $order->update([
                                        'config_details' => $finalConfig,
                                        'expires_at' => $newExpiresAt,
                                        'panel_username' => $uniqueUsername
                                    ]);
                                    $user->notifications()->create([
                                        'type' => 'service_activated_admin',
                                        'title' => 'سرویس شما فعال شد!',
                                        'message' => "خرید سرویس {$plan->name} توسط مدیر تایید و فعال شد.",
                                        'link' => route('dashboard', ['tab' => 'my_services']),
                                    ]);
                                }

                                $order->update(['status' => 'paid']);
                                $description = ($isRenewal ? "تمدید سرویس" : "خرید سرویس") . " {$plan->name}";
                                Transaction::create(['user_id' => $user->id, 'order_id' => $order->id, 'amount' => $plan->price, 'type' => 'purchase', 'status' => 'completed', 'description' => $description]);
                                OrderPaid::dispatch($order);
                                Notification::make()->title('عملیات با موفقیت انجام شد.')->success()->send();

                                if ($user->telegram_chat_id) {
                                    try {
                                        $telegramMessage = $isRenewal
                                            ? "✅ سرویس شما (*{$plan->name}*) با موفقیت تمدید شد.\n\n❗️*نکته مهم:* لینک اشتراک شما تغییر کرده است. لطفاً لینک جدید زیر را کپی و در نرم‌افزار خود آپدیت کنید:\n\n`" . $finalConfig . "`"
                                            : "✅ سرویس شما (*{$plan->name}*) با موفقیت فعال شد.\n\nاطلاعات کانفیگ شما:\n`" . $finalConfig . "`\n\nمی‌توانید لینک بالا را کپی کرده و در نرم‌افزار خود import کنید.";
                                        Telegram::setAccessToken($settings->get('telegram_bot_token'));
                                        Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $telegramMessage, 'parse_mode' => 'Markdown']);
                                    } catch (\Exception $e) {
                                        Log::error('Failed to send Telegram notification: ' . $e->getMessage());
                                    }
                                }
                            }
                        });
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getRelations(): array { return []; }
    public static function getPages(): array {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
