<?php

namespace App\Http\Controllers;

use App\Events\OrderPaid;
use App\Models\DiscountCode;
use App\Models\DiscountCodeUsage;
use App\Models\Inbound;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Services\ManualCryptoService;
use App\Services\MarzbanService;
use App\Services\PlisioService;
use App\Services\XmplusProvisioningService;
use App\Services\XUIService;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OrderController extends Controller
{
    /**
     * Create a new pending order for a specific plan.
     */
    public function store(Plan $plan)
    {
        $order = Auth::user()->orders()->create([
            'plan_id' => $plan->id,
            'status' => 'pending',
            'source' => 'web',
            'discount_amount' => 0,
            'discount_code_id' => null,
        ]);

        Auth::user()->notifications()->create([
            'type' => 'new_order_created',
            'title' => 'سفارش جدید شما ثبت شد!',
            'message' => "سفارش #{$order->id} برای پلن {$plan->name} با موفقیت ثبت شد و در انتظار پرداخت است.",
            'link' => route('order.show', $order->id),
        ]);

        return redirect()->route('order.show', $order->id);
    }

    /**
     * Show the payment method selection page for an order.
     */
    public function show(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403, 'شما به این صفحه دسترسی ندارید.');
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        return view('payment.show', ['order' => $order]);
    }

    /**
     * Show the bank card details and receipt upload form.
     */
    public function processCardPayment(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403);
        }


        $order->update(['payment_method' => 'card']);


        $originalAmount = $order->plan ? $order->plan->price : $order->amount;
        $discountAmount = session('discount_amount', 0);
        $finalAmount = $originalAmount - $discountAmount;

        $order->update([
            'discount_amount' => $discountAmount,
            'amount' => $finalAmount
        ]);


        return redirect()->route('payment.card.show', $order->id);
    }

    /**
     * Show the form to enter the wallet charge amount.
     */
    public function showChargeForm()
    {
        return view('wallet.charge');
    }

    /**
     * Create a new pending order for charging the wallet.
     */
    public function createChargeOrder(Request $request)
    {
        $request->validate(['amount' => 'required|numeric|min:10000']);
        $order = Auth::user()->orders()->create([
            'plan_id' => null,
            'amount' => $request->amount,
            'status' => 'pending',
            'source' => 'web',
        ]);

        Auth::user()->notifications()->create([
            'type' => 'wallet_charge_pending',
            'title' => 'درخواست شارژ کیف پول ثبت شد!',
            'message' => "سفارش شارژ کیف پول به مبلغ " . number_format($request->amount) . " تومان در انتظار پرداخت شماست.",
            'link' => route('order.show', $order->id),
        ]);

        return redirect()->route('order.show', $order->id);
    }

    /**
     * Create a new pending order to renew an existing service.
     */
    public function renew(Order $order)
    {
        if (Auth::id() !== $order->user_id || $order->status !== 'paid') {
            abort(403);
        }

        $newOrder = $order->replicate();
        $newOrder->created_at = now();
        $newOrder->status = 'pending';
        $newOrder->source = 'web';
        $newOrder->config_details = null;
        $newOrder->expires_at = null;
        $newOrder->renews_order_id = $order->id;
        $newOrder->discount_amount = 0;
        $newOrder->discount_code_id = null;
        $newOrder->amount = $order->plan->price; // مبلغ اصلی بدون تخفیف
        $newOrder->save();

        Auth::user()->notifications()->create([
            'type' => 'renewal_order_created',
            'title' => 'درخواست تمدید سرویس ثبت شد!',
            'message' => "سفارش تمدید سرویس {$order->plan->name} با موفقیت ثبت شد و در انتظار پرداخت است.",
            'link' => route('order.show', $newOrder->id),
        ]);

        return redirect()->route('order.show', $newOrder->id)->with('status', 'سفارش تمدید شما ایجاد شد. لطفاً هزینه را پرداخت کنید.');
    }

    /**
     * Apply discount code to an order.
     */
    public function applyDiscountCode(Request $request, Order $order)
    {
        if (Auth::id() !== $order->user_id || $order->status !== 'pending') {
            Log::warning('Discount Code - Access Denied', [
                'user_id' => Auth::id(),
                'order_user_id' => $order->user_id,
                'order_status' => $order->status
            ]);
            return response()->json(['error' => 'دسترسی غیرمجاز یا سفارش نامعتبر'], 403);
        }

        Log::info('Discount Code Search', [
            'code' => $request->code,
            'current_time' => now()->toDateTimeString(),
            'order_id' => $order->id
        ]);

        $code = DiscountCode::where('code', $request->code)->first();

        if (!$code) {
            Log::error('Discount Code Not Found', ['code' => $request->code]);
            return response()->json(['error' => 'کد تخفیف پیدا نشد. دقت کنید کد را صحیح وارد کنید.'], 400);
        }

        Log::info('Discount Code Found', [
            'code' => $code->toArray(),
            'server_time' => now()->toDateTimeString(),
            'is_active' => $code->is_active,
            'starts_at' => $code->starts_at?->toDateTimeString(),
            'expires_at' => $code->expires_at?->toDateTimeString(),
        ]);

        if (!$code->is_active) {
            return response()->json(['error' => 'کد تخفیف غیرفعال است'], 400);
        }

        if ($code->starts_at && $code->starts_at > now()) {
            return response()->json(['error' => 'کد تخفیف هنوز شروع نشده. زمان شروع: ' . $code->starts_at], 400);
        }

        if ($code->expires_at && $code->expires_at < now()) {
            return response()->json(['error' => 'کد تخفیف منقضی شده. زمان انقضا: ' . $code->expires_at], 400);
        }

        $totalAmount = $order->plan_id ? $order->plan->price : $order->amount;

        Log::info('Order Info for Discount', [
            'order_id' => $order->id,
            'plan_id' => $order->plan_id,
            'amount' => $totalAmount,
            'is_wallet' => !$order->plan_id,
            'is_renewal' => (bool)$order->renews_order_id
        ]);

        $isWalletCharge = !$order->plan_id;
        $isRenewal = (bool)$order->renews_order_id;

        if (!$code->isValidForOrder(
            amount: $totalAmount,
            planId: $order->plan_id,
            isWallet: $isWalletCharge,
            isRenewal: $isRenewal
        )) {
            return response()->json(['error' => 'این کد تخفیف برای این سفارش قابل استفاده نیست. شرایط استفاده را بررسی کنید.'], 400);
        }

        $discountAmount = $code->calculateDiscount($totalAmount);
        $finalAmount = $totalAmount - $discountAmount;

        Log::info('Discount Calculated', [
            'original_amount' => $totalAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount
        ]);

        // ذخیره هم در دیتابیس و هم در سشن
        $order->update([
            'discount_amount' => $discountAmount,
            'discount_code_id' => $code->id
        ]);

        session([
            'discount_code' => $code->code,
            'discount_amount' => $discountAmount,
            'discount_applied_order_id' => $order->id
        ]);

        return response()->json([
            'success' => true,
            'discount' => number_format($discountAmount),
            'original_amount' => number_format($totalAmount),
            'final_amount' => number_format($finalAmount),
            'message' => "کد تخفیف اعمال شد! تخفیف: " . number_format($discountAmount) . " تومان"
        ]);
    }

    /**
     * Handle the submission of the payment receipt file.
     */


    public function showCardPaymentPage(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403);
        }


        if ($order->status === 'paid') {
            return redirect()->route('dashboard')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }


        $settings = Setting::all()->pluck('value', 'key');


        $finalAmount = $order->amount;

        return view('payment.card-receipt', [
            'order' => $order,
            'settings' => $settings,
            'finalAmount' => $finalAmount,
        ]);
    }

    public function submitCardReceipt(Request $request, Order $order)
    {
        $request->validate(['receipt' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048']);

        // اگر مبلغ نهایی قبلاً ذخیره نشده، از سشن بخون
        if ($order->amount == ($order->plan->price ?? 0)) {
            $discountAmount = session('discount_amount', 0);
            $finalAmount = ($order->plan->price ?? $order->amount) - $discountAmount;

            $order->update([
                'discount_amount' => $discountAmount,
                'amount' => $finalAmount
            ]);
        }

        $path = $request->file('receipt')->store('receipts', 'public');

        // ذخیره فقط رسید (مبلغ قبلاً تنظیم شده)
        $order->update(['card_payment_receipt' => $path]);

        // بقیه کد تخفیف رو فقط اگر ثبت نشده
        if (session('discount_code') && session('discount_applied_order_id') == $order->id) {
            $discountCode = DiscountCode::where('code', session('discount_code'))->first();

            if ($discountCode && !DiscountCodeUsage::where('order_id', $order->id)->exists()) {
                DiscountCodeUsage::create([
                    'discount_code_id' => $discountCode->id,
                    'user_id' => Auth::id(),
                    'order_id' => $order->id,
                    'discount_amount' => session('discount_amount', 0),
                    'original_amount' => $order->plan->price ?? $order->amount,
                ]);

                $discountCode->increment('used_count');
            }
        }

        Auth::user()->notifications()->create([
            'type' => 'card_receipt_submitted',
            'title' => 'رسید پرداخت شما ارسال شد!',
            'message' => "رسید پرداخت سفارش #{$order->id} با موفقیت دریافت شد و در انتظار تایید مدیر است.",
            'link' => route('order.show', $order->id),
        ]);

        session()->forget(['discount_code', 'discount_amount', 'discount_applied_order_id']);

        return redirect()->route('dashboard')->with('status', 'رسید شما با موفقیت ارسال شد. پس از تایید توسط مدیر، سرویس شما فعال خواهد شد.');
    }

    /**
     * Process instant payment from the user's wallet balance.
     */
    public function processWalletPayment(Order $order)
    {
        if (auth()->id() !== $order->user_id) {
            abort(403);
        }

        if (!$order->plan) {
            return redirect()->back()->with('error', 'این عملیات برای شارژ کیف پول مجاز نیست.');
        }

        $user = auth()->user();
        $plan = $order->plan;
        $originalPrice = $plan->price;

        $discountAmount = $order->discount_amount ?? session('discount_amount', 0);
        $finalPrice = $originalPrice - $discountAmount;

        if ($user->balance < $finalPrice) {
            return redirect()->back()->with('error', 'موجودی کیف پول شما برای انجام این عملیات کافی نیست.');
        }

        try {
            DB::transaction(function () use ($order, $user, $plan, $originalPrice, $finalPrice, $discountAmount) {

                $user->decrement('balance', $finalPrice);


                $user->notifications()->create([
                    'type' => 'wallet_deducted',
                    'title' => 'کسر از کیف پول شما',
                    'message' => "مبلغ " . number_format($finalPrice) . " تومان برای سفارش #{$order->id} از کیف پول شما کسر شد.",
                    'link' => route('dashboard', ['tab' => 'order_history']),
                ]);

                // ثبت استفاده از کد تخفیف
                if (session('discount_code') && session('discount_applied_order_id') == $order->id) {
                    $discountCode = DiscountCode::where('code', session('discount_code'))->first();

                    if ($discountCode && !DiscountCodeUsage::where('order_id', $order->id)->exists()) {
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

                // تنظیمات
                $settings = Setting::all()->pluck('value', 'key');
                $success = false;
                $finalConfig = '';
                $extraOrderAttrs = [];
                $panelType = $settings->get('panel_type');
                $isRenewal = (bool) $order->renews_order_id;

                $originalOrder = $isRenewal ? Order::find($order->renews_order_id) : null;
                if ($isRenewal && !$originalOrder) {
                    throw new \Exception('سفارش اصلی جهت تمدید یافت نشد.');
                }

                // برای تمدید، از ID سفارش اصلی استفاده کن
                $uniqueUsername = $order->panel_username ?? "user-{$user->id}-order-" . ($isRenewal ? $originalOrder->id : $order->id);
                $newExpiresAt = $isRenewal
                    ? (new \DateTime($originalOrder->expires_at))->modify("+{$plan->duration_days} days")
                    : now()->addDays($plan->duration_days);

                $timestamp = $newExpiresAt->getTimestamp();

                // ==========================================
                // پنل MARZBAN
                // ==========================================
                if ($panelType === 'marzban') {
                    $marzbanService = new MarzbanService(
                        $settings->get('marzban_host'),
                        $settings->get('marzban_sudo_username'),
                        $settings->get('marzban_sudo_password'),
                        $settings->get('marzban_node_hostname')
                    );

                    $userData = [
                        'expire' => $timestamp,
                        'data_limit' => $plan->volume_gb * 1073741824
                    ];

                    $response = $isRenewal
                        ? $marzbanService->updateUser($uniqueUsername, $userData)
                        : $marzbanService->createUser(array_merge($userData, ['username' => $uniqueUsername]));

                    if ($response && (isset($response['subscription_url']) || isset($response['username']))) {
                        $finalConfig = $marzbanService->generateSubscriptionLink($response);
                        $success = true;
                    }
                }

                // ==========================================
                // پنل X-UI (SANAEI)
                // ==========================================
                elseif ($panelType === 'xui') {
                    $xuiService = new XUIService(
                        $settings->get('xui_host'),
                        $settings->get('xui_user'),
                        $settings->get('xui_pass')
                    );

                    $defaultInboundId = $settings->get('xui_default_inbound_id');

                    if (empty($defaultInboundId)) {
                        throw new \Exception('تنظیمات اینباند پیش‌فرض برای X-UI یافت نشد.');
                    }

                    $inbound = Inbound::findByPanelInboundId($defaultInboundId);

                    if (!$inbound || !$inbound->inbound_data) {
                        throw new \Exception("اینباند X-UI با id «{$defaultInboundId}» در دیتابیس نیست؛ از ادمین → اینباندها همگام‌سازی با X-UI را اجرا کنید.");
                    }

                    $inboundData = $inbound->inbound_data;

                    if (!$xuiService->login()) {
                        throw new \Exception('خطا در لاگین به پنل X-UI.');
                    }

                    $clientData = [
                        'email' => $uniqueUsername,
                        'total' => $plan->volume_gb * 1073741824,
                        'expiryTime' => $timestamp * 1000
                    ];

                    // ==========================================
                    // تمدید سرویس در X-UI
                    // ==========================================
                    if ($isRenewal) {
                        $linkType = $settings->get('xui_link_type', 'single');
                        $originalConfig = $originalOrder->config_details;

                        // پیدا کردن کلاینت توسط ایمیل
                        $clients = $xuiService->getClients($inboundData['id']);

                        if (empty($clients)) {
                            throw new \Exception('❌ هیچ کلاینتی در اینباند یافت نشد.');
                        }

                        $client = collect($clients)->firstWhere('email', $uniqueUsername);

                        if (!$client) {
                            throw new \Exception("❌ کلاینت با ایمیل {$uniqueUsername} یافت نشد. امکان تمدید وجود ندارد.");
                        }

                        // آماده‌سازی داده برای بروزرسانی
                        $clientData['id'] = $client['id'];

                        // اگرلینک subscription است، subId را هم اضافه کن
                        if ($linkType === 'subscription' && isset($client['subId'])) {
                            $clientData['subId'] = $client['subId'];
                        }

                        // آپدیت کلاینت
                        $response = $xuiService->updateClient($inboundData['id'], $client['id'], $clientData);

                        if ($response && isset($response['success']) && $response['success']) {
                            $finalConfig = $originalConfig; // لینک قبلی
                            $success = true;
                        } else {
                            $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                            throw new \Exception("❌ خطا در بروزرسانی کلاینت: " . $errorMsg);
                        }
                    }

                    // ==========================================
                    // سفارش جدید در X-UI
                    // ==========================================
                    else {
                        $response = $xuiService->addClient($inboundData['id'], $clientData);

                        if ($response && isset($response['success']) && $response['success']) {
                            $linkType = $settings->get('xui_link_type', 'single');

                            if ($linkType === 'subscription') {
                                $subId = $response['generated_subId'];
                                $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');

                                if ($subBaseUrl && $subId) {
                                    $finalConfig = $subBaseUrl . '/sub/' . $subId;
                                    $success = true;
                                } else {
                                    throw new \Exception('خطا در ساخت لینک سابسکریپشن.');
                                }
                            } else {
                                $uuid = $response['generated_uuid'];
                                $streamSettings = $inboundData['streamSettings'] ?? [];

                                if (is_string($streamSettings)) {
                                    $streamSettings = json_decode($streamSettings, true) ?? [];
                                }

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
                            $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                            throw new \Exception('خطا در ساخت کاربر در پنل X-UI: ' . $errorMsg);
                        }
                    }

                    if (! $success) {
                        throw new \Exception('خطا در ارتباط با سرور برای فعال‌سازی سرویس.');
                    }
                } elseif ($panelType === 'xmplus') {
                    $result = XmplusProvisioningService::provisionPurchase(
                        $settings,
                        $user,
                        $plan,
                        $order,
                        $isRenewal,
                        $originalOrder,
                        true
                    );
                    $finalConfig = $result['final_config'];
                    $success = true;
                    $extraOrderAttrs = array_filter([
                        'panel_username' => $result['panel_username'],
                        'panel_client_id' => $result['panel_client_id'],
                    ], fn ($v) => $v !== null && $v !== '');
                } else {
                    throw new \Exception('نوع پنل در تنظیمات مشخص نشده است.');
                }

                if (! $success) {
                    throw new \Exception('خطا در ارتباط با سرور برای فعال‌سازی سرویس.');
                }

                // ==========================================
                // ذخیره سفارشات
                // ==========================================
                if ($isRenewal) {
                    $originalOrder->update(array_merge([
                        'config_details' => $finalConfig,
                        'expires_at' => $newExpiresAt->format('Y-m-d H:i:s'),
                    ], $panelType === 'xmplus' ? $extraOrderAttrs : []));

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
                    ], $panelType === 'xmplus' ? $extraOrderAttrs : []));

                    $user->notifications()->create([
                        'type' => 'service_purchased',
                        'title' => 'سرویس شما فعال شد!',
                        'message' => "سرویس {$plan->name} با موفقیت خریداری و فعال شد.",
                        'link' => route('dashboard', ['tab' => 'my_services']),
                    ]);
                }

                // آپدیت وضعیت سفارش
                $order->update([
                    'status' => 'paid',
                    'payment_method' => 'wallet',
                ]);

                // تراکنش
                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $order->id,
                    'amount' => $finalPrice,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => ($isRenewal ? 'تمدید سرویس' : 'خرید سرویس')." {$plan->name} از کیف پول"
                        .($discountAmount > 0 ? ' (تخفیف: '.number_format($discountAmount).' تومان)' : ''),
                ]);

                OrderPaid::dispatch($order);
            });
        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            Auth::user()->notifications()->create([
                'type' => 'payment_failed',
                'title' => 'خطا در پرداخت با کیف پول!',
                'message' => "پرداخت سفارش شما با خطا مواجه شد: " . $e->getMessage(),
                'link' => route('dashboard', ['tab' => 'order_history']),
            ]);

            return redirect()->route('dashboard')->with('error', 'پرداخت با خطا مواجه شد: ' . $e->getMessage());
        }


        session()->forget(['discount_code', 'discount_amount', 'discount_applied_order_id']);

        return redirect()->route('dashboard')->with('status', 'سرویس شما با موفقیت فعال شد.');
    }
    /**
     * پرداخت از طریق Plisio (کریپتو) — هدایت به صفحه فاکتور Plisio.
     */
    public function processCryptoPayment(Order $order)
    {
        if (Auth::id() !== $order->user_id) {
            abort(403);
        }

        if ($order->status === 'paid') {
            return redirect()->route('dashboard')->with('status', 'این سفارش قبلاً پرداخت شده است.');
        }

        $plisio = PlisioService::fromDatabase();
        if (! $plisio->isEnabled()) {
            return redirect()->back()->with('error', 'درگاه پرداخت Plisio فعال نیست. از پنل ادمین آن را فعال و API Key را ذخیره کنید.');
        }

        if ($order->plan_id) {
            $originalAmount = $order->plan->price;
            $discountAmount = (float) ($order->discount_amount ?? 0);
            if ($discountAmount <= 0 && (int) session('discount_applied_order_id') === (int) $order->id) {
                $discountAmount = (float) session('discount_amount', 0);
            }
            $finalAmount = max(0, $originalAmount - $discountAmount);
            $order->update([
                'payment_method' => 'plisio',
                'discount_amount' => $discountAmount,
                'amount' => $finalAmount,
            ]);
        } else {
            $order->update([
                'payment_method' => 'plisio',
            ]);
        }

        try {
            $invoice = $plisio->createInvoice($order, Auth::user()->email);
            $order->update(['plisio_txn_id' => $invoice['txn_id']]);
        } catch (\Throwable $e) {
            Log::error('Plisio invoice: '.$e->getMessage());

            return redirect()->back()->with('error', 'ساخت فاکتور Plisio ناموفق بود: '.$e->getMessage());
        }

        return redirect()->away($invoice['invoice_url']);
    }

    protected function assertManualCryptoOrderOwned(Order $order): void
    {
        if (Auth::id() !== $order->user_id || $order->status !== 'pending') {
            abort(403, 'دسترسی غیرمجاز یا سفارش نامعتبر.');
        }
    }

    public function showManualCrypto(Request $request, Order $order)
    {
        $this->assertManualCryptoOrderOwned($order);
        $settings = Setting::all()->pluck('value', 'key');
        if (! ManualCryptoService::isEnabled($settings)) {
            return redirect()->route('order.show', $order)->with('error', 'پرداخت USDT/USDC دستی در حال حاضر غیرفعال است.');
        }
        $networks = ManualCryptoService::availableNetworks($settings);
        if ($networks === []) {
            return redirect()->route('order.show', $order)->with('error', 'هیچ شبکه‌ای برای پرداخت دستی پیکربندی نشده است.');
        }

        if ($request->boolean('reset') && $order->crypto_network) {
            $order->update([
                'payment_method' => null,
                'crypto_network' => null,
                'crypto_tx_hash' => null,
                'crypto_amount_expected' => null,
                'crypto_payment_proof' => null,
            ]);

            return redirect()->route('payment.manual-crypto', $order);
        }

        if (! $order->crypto_network) {
            return view('payment.manual-crypto-pick', [
                'order' => $order,
                'networks' => $networks,
            ]);
        }

        $addr = ManualCryptoService::address($settings, $order->crypto_network);
        $label = ManualCryptoService::label($order->crypto_network);
        $expectedFormatted = ManualCryptoService::formatAmountForDisplay(
            (float) ($order->crypto_amount_expected ?? 0),
            $settings
        );

        return view('payment.manual-crypto-submit', [
            'order' => $order,
            'addr' => $addr,
            'label' => $label,
            'expectedFormatted' => $expectedFormatted,
        ]);
    }

    public function pickManualCryptoNetwork(Request $request, Order $order)
    {
        $this->assertManualCryptoOrderOwned($order);
        $settings = Setting::all()->pluck('value', 'key');
        if (! ManualCryptoService::isEnabled($settings)) {
            return redirect()->route('order.show', $order)->with('error', 'پرداخت USDT/USDC دستی غیرفعال است.');
        }

        $request->validate(['network' => 'required|string|max:32']);
        $network = $request->input('network');
        if (! ManualCryptoService::validateNetwork($network) || ! ManualCryptoService::networkIsReady($settings, $network)) {
            return redirect()->back()->with('error', 'شبکه انتخاب‌شده معتبر نیست یا آدرس/نرخ تنظیم نشده است.');
        }

        $amountToman = (float) $order->amount;
        $crypto = ManualCryptoService::expectedAmount($amountToman, $settings, $network);
        if ($crypto === null) {
            return redirect()->back()->with('error', 'نرخ تبدیل معتبر نیست.');
        }

        $order->update([
            'payment_method' => 'manual_crypto',
            'crypto_network' => $network,
            'crypto_amount_expected' => $crypto,
            'crypto_tx_hash' => null,
            'crypto_payment_proof' => null,
        ]);

        return redirect()->route('payment.manual-crypto', $order)
            ->with('status', 'شبکه انتخاب شد. پس از واریز، شناسه تراکنش یا تصویر را ثبت کنید.');
    }

    public function submitManualCryptoProof(Request $request, Order $order)
    {
        $this->assertManualCryptoOrderOwned($order);
        if (! ManualCryptoService::databaseReady()) {
            return redirect()->route('order.show', $order)->with('error', 'پایگاه داده به‌روز نشده است. مدیر سرور باید دستور php artisan migrate را اجرا کند.');
        }
        if ($order->payment_method !== 'manual_crypto' || ! $order->crypto_network) {
            return redirect()->route('payment.manual-crypto', $order)->with('error', 'ابتدا شبکه پرداخت را انتخاب کنید.');
        }

        $request->validate([
            'tx_hash' => 'nullable|string|max:120',
            'screenshot' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:4096',
        ]);

        $hash = trim((string) $request->input('tx_hash', ''));
        if ($hash === '' && ! $request->hasFile('screenshot')) {
            return redirect()->back()->with('error', 'حداقل یکی از موارد «شناسه تراکنش» یا «تصویر تراکنش» را ارسال کنید.');
        }

        $updates = [];
        if ($hash !== '') {
            $updates['crypto_tx_hash'] = $hash;
        }
        if ($request->hasFile('screenshot')) {
            $updates['crypto_payment_proof'] = $request->file('screenshot')->store('crypto-proofs', 'public');
        }
        if ($updates !== []) {
            $order->update($updates);
        }

        Auth::user()->notifications()->create([
            'type' => 'manual_crypto_submitted',
            'title' => 'اطلاعات پرداخت کریپتو ثبت شد',
            'message' => "سفارش #{$order->id}: پس از تأیید مدیر، نتیجه اعلام می‌شود.",
            'link' => route('order.show', $order->id),
        ]);

        return redirect()->route('dashboard')->with('status', 'اطلاعات پرداخت ثبت شد. پس از تأیید مدیر، سفارش تکمیل می‌شود.');
    }
}
