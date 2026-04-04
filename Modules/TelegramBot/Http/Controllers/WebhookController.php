<?php

namespace Modules\TelegramBot\Http\Controllers;

use App\Models\Order;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\TelegramBotSetting;
use App\Services\PlisioService;
use App\Services\XUIService;
use App\Models\User;
use App\Services\MarzbanService;
use App\Models\Inbound;
use Modules\Ticketing\Events\TicketCreated;
use Modules\Ticketing\Events\TicketReplied;
use Modules\Ticketing\Models\Ticket;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Hash;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Str;
use App\Models\DiscountCode;
use App\Models\DiscountCodeUsage;
use App\Support\TelegramBotToken;
use Carbon\Carbon;
use Telegram\Bot\FileUpload\InputFile;

class WebhookController extends Controller
{
    protected $settings;


    public function sendBroadcastMessage(string $chatId, string $message): bool
    {
        try {
            if (!$this->settings) {
                $this->settings = \App\Models\Setting::all()->pluck('value', 'key');
            }

            $botToken = TelegramBotToken::normalize($this->settings->get('telegram_bot_token'));
            if (!$botToken) {
                \Log::error('❌ Cannot send broadcast message: bot token is not set.');
                return false;
            }

            \Telegram\Bot\Laravel\Facades\Telegram::setAccessToken($botToken);

            $title = "📢 *اعلان ویژه از سوی تیم مدیریت*";
            $divider = str_repeat('━', 20);
            $footer = "💠 *با تشکر از همراهی شما* 💠";

            $formattedMessage = $this->escape($message);

            $fullMessage = "{$title}\n\n{$divider}\n\n📝 *{$formattedMessage}*\n\n{$divider}\n\n{$footer}";

            \Telegram\Bot\Laravel\Facades\Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $fullMessage,
                'parse_mode' => 'MarkdownV2',
            ]);

            \Log::info("✅ Broadcast message sent successfully to chat {$chatId}");
            return true;
        } catch (\Exception $e) {
            \Log::warning("⚠️ Failed to send broadcast message to user {$chatId}: " . $e->getMessage());
            return false;
        }
    }


    public function sendSingleMessageToUser(string $chatId, string $message): bool
    {
        try {
            if (!$this->settings) {
                $this->settings = \App\Models\Setting::all()->pluck('value', 'key');
            }
            $botToken = TelegramBotToken::normalize($this->settings->get('telegram_bot_token'));
            if (!$botToken) {
                \Illuminate\Support\Facades\Log::error('Cannot send single Telegram message: bot token is not set.');
                return false;
            }
            \Telegram\Bot\Laravel\Facades\Telegram::setAccessToken($botToken);

            $header = "📢 *پیام فوری از مدیریت*";
            $notice = "⚠️ این یک پیام اطلاع‌رسانی یک‌طرفه از پنل ادمین است و پاسخ دادن به آن در این چت، پیگیری نخواهد شد\\.";

            $adminMessageLines = explode("\n", $message);
            $formattedMessage = implode("\n", array_map(fn($line) => "> " . trim($line), $adminMessageLines));

            $fullMessage = "{$header}\n\n{$this->escape($notice)}\n\n{$formattedMessage}";

            \Telegram\Bot\Laravel\Facades\Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $fullMessage,
                'parse_mode' => 'MarkdownV2',
            ]);

            \Illuminate\Support\Facades\Log::info("Admin sent message to user {$chatId}.", ['message' => $message]);
            return true;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send single Telegram message: ' . $e->getMessage(), ['chat_id' => $chatId, 'message' => $message]);
            return false;
        }
    }


    public function handle(Request $request)
    {
        Log::info('Telegram Webhook Received:', $request->all());

        try {
            $this->settings = Setting::all()->pluck('value', 'key');
            $botToken = TelegramBotToken::normalize($this->settings->get('telegram_bot_token'));
            if (!$botToken) {
                Log::warning('Telegram bot token is not set.');
                return response('ok', 200);
            }
            Telegram::setAccessToken($botToken);
            $update = Telegram::getWebhookUpdate();

            if ($update->isType('callback_query')) {
                $this->handleCallbackQuery($update);
            } elseif ($update->has('message')) {
                $message = $update->getMessage();
                if ($message->has('text')) {
                    $this->handleTextMessage($update);
                } elseif ($message->has('photo')) {
                    $this->handlePhotoMessage($update);
                }
            }
        } catch (\Exception $e) {
            Log::error('Telegram Bot Error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        }
        return response('ok', 200);
    }


    protected function handleTextMessage($update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $text = trim($message->getText() ?? '');
        $user = User::where('telegram_chat_id', $chatId)->first();

        if ($user && !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId);
            return;
        }

        if (!$user) {
            $userFirstName = $message->getFrom()->getFirstName() ?? 'کاربر';
            $password = Str::random(10);
            $user = User::create([
                'name' => $userFirstName,
                'email' => $chatId . '@telegram.user',
                'password' => Hash::make($password),
                'telegram_chat_id' => $chatId,
                'referral_code' => Str::random(8),
            ]);

            if (!$this->isUserMemberOfChannel($user)) {
                $this->showChannelRequiredMessage($chatId);
                return;
            }

            $telegramSettings = \App\Models\TelegramBotSetting::pluck('value', 'key');
            $welcomeMessage = $telegramSettings->get('welcome_message', "🌟 خوش آمدید {$userFirstName} عزیز!\n\nبرای شروع، یکی از گزینه‌های منو را انتخاب کنید:");
            $welcomeMessage = str_replace('{userFirstName}', $userFirstName, $welcomeMessage);

            if (Str::startsWith($text, '/start ')) {
                $referralCode = Str::after($text, '/start ');
                $referrer = User::where('referral_code', $referralCode)->first();

                if ($referrer && $referrer->id !== $user->id) {
                    $user->referrer_id = $referrer->id;
                    $user->save();
                    $welcomeGift = (int) $this->settings->get('referral_welcome_gift', 0);
                    if ($welcomeGift > 0) {
                        $user->increment('balance', $welcomeGift);
                        $welcomeMessage .= "\n\n🎁 هدیه خوش‌آمدگویی: " . number_format($welcomeGift) . " تومان به کیف پول شما اضافه شد.";
                    }
                    if ($referrer->telegram_chat_id) {
                        $referrerMessage = "👤 خبر خوب!\n\nکاربر جدیدی با نام «{$userFirstName}» با لینک دعوت شما به ربات پیوست.";
                        try {
                            Telegram::sendMessage([
                                'chat_id' => (int) $referrer->telegram_chat_id,
                                'text' => $referrerMessage,
                            ]);
                        } catch (\Exception $e) {
                            Log::error("Failed to send referral notification: " . $e->getMessage());
                        }
                    }
                }
            }

            Telegram::sendMessage([
                'chat_id' => (int) $chatId,
                'text' => $welcomeMessage,
                'reply_markup' => $this->getReplyMainMenu(),
            ]);
            return;
        }

        if ($user->bot_state) {
            if ($user->bot_state === 'awaiting_deposit_amount') {
                $this->processDepositAmount($user, $text);
            } elseif (Str::startsWith($user->bot_state, 'awaiting_new_ticket_') || Str::startsWith($user->bot_state, 'awaiting_ticket_reply')) {
                $this->processTicketConversation($user, $text, $update);
            } elseif (Str::startsWith($user->bot_state, 'awaiting_discount_code|')) {
                $orderId = Str::after($user->bot_state, 'awaiting_discount_code|');
                $this->processDiscountCode($user, $orderId, $text);
            }
            elseif (Str::startsWith($user->bot_state, 'awaiting_username_for_order|')) {
                $planId = Str::after($user->bot_state, 'awaiting_username_for_order|');
                $this->processUsername($user, $planId, $text);
            }

            return;
        }

        switch ($text) {
            case '🛒 خرید سرویس':
                $this->sendPlans($chatId);
                break;
            case '🛠 سرویس‌های من':
                $this->sendMyServices($user);
                break;
            case '💰 کیف پول':
                $this->sendWalletMenu($user);
                break;
            case '📜 تاریخچه تراکنش‌ها':
                $this->sendTransactions($user);
                break;
            case '💬 پشتیبانی':
                $this->showSupportMenu($user);
                break;
            case '🎁 دعوت از دوستان':
                $this->sendReferralMenu($user);
                break;
            case '📚 راهنمای اتصال':
                $this->sendTutorialsMenu($chatId);
                break;
            case '🧪 اکانت تست':
                $this->handleTrialRequest($user);
                break;

            case '/start':
                $telegramSettings = \App\Models\TelegramBotSetting::pluck('value', 'key');
                $startMessage = $telegramSettings->get('start_message', 'سلام مجدد! لطفاً یک گزینه را انتخاب کنید:');
                try {
                    Telegram::sendMessage([
                        'chat_id' => (int) $chatId,
                        'text' => $startMessage,
                        'reply_markup' => $this->getReplyMainMenu(),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Telegram /start sendMessage failed: '.$e->getMessage(), ['chat_id' => $chatId]);
                }
                break;
            default:
                try {
                    Telegram::sendMessage([
                        'chat_id' => (int) $chatId,
                        'text' => 'دستور شما نامفهوم است. لطفاً از دکمه‌های منو استفاده کنید.',
                        'reply_markup' => $this->getReplyMainMenu(),
                    ]);
                } catch (\Throwable $e) {
                    Log::error('Telegram default reply failed: '.$e->getMessage(), ['chat_id' => $chatId]);
                }
                break;
        }
    }


    protected function processUsername($user, $planId, $username)
    {
        $username = trim($username);


        if (strlen($username) < 3) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ نام کاربری باید حداقل ۳ کاراکتر باشد."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForUsername($user, $planId);
            return;
        }


        if (!preg_match('/^[a-zA-Z0-9]+$/', $username)) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ نام کاربری فقط می‌تواند شامل حروف انگلیسی و اعداد باشد."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForUsername($user, $planId);
            return;
        }


        $existingOrder = Order::where('panel_username', $username)->where('status', 'paid')->first();
        if ($existingOrder) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ این نام کاربری قبلاً استفاده شده است. لطفاً نام دیگری وارد کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForUsername($user, $planId);
            return;
        }


        $this->startPurchaseProcess($user, $planId, $username);
    }

    protected function promptForUsername($user, $planId, $messageId = null)
    {
        $user->update(['bot_state' => 'awaiting_username_for_order|' . $planId]);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $message = "👤 *انتخاب نام کاربری سرویس*\n\n";
        $message .= "لطفاً یک نام کاربری انگلیسی برای سرویس خود وارد کنید.\n";
        $message .= "🔹 فقط حروف انگلیسی و اعداد مجاز است (حداقل ۳ حرف).\n";
        $message .= "🔹 مثال: `arvin123` یا `myvpn`";
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function handleCallbackQuery($update)
    {
        $callbackQuery = $update->getCallbackQuery();
        $chatId = $callbackQuery->getMessage()->getChat()->getId();
        $messageId = $callbackQuery->getMessage()->getMessageId();
        $data = $callbackQuery->getData();
        $user = User::where('telegram_chat_id', $chatId)->first();

        if ($user && !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId, $messageId);
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'ابتدا باید در کانال عضو شوید!',
                'show_alert' => true
            ]);
            return;
        }

        if (Str::startsWith($data, 'show_duration_')) {
            $durationDays = (int)Str::after($data, 'show_duration_');
            $this->sendPlansByDuration($chatId, $durationDays, $messageId);
            return;
        }

        if (Str::startsWith($data, 'show_service_')) {
            $orderId = Str::after($data, 'show_service_');
            $this->showServiceDetails($user, $orderId, $messageId);
            return;
        }

        if (!$user || !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId, $messageId);
            Telegram::answerCallbackQuery([
                'callback_query_id' => $callbackQuery->getId(),
                'text' => 'ابتدا باید در کانال عضو شوید!',
                'show_alert' => true
            ]);
            return;
        }

        try {
            Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
        } catch (\Exception $e) { Log::warning('Could not answer callback query: ' . $e->getMessage()); }

        if (!$user) {
            Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ کاربر یافت نشد. لطفاً با دستور /start ربات را مجدداً راه‌اندازی کنید."), 'parse_mode' => 'MarkdownV2']);
            return;
        }

        if (!Str::startsWith($data, ['/deposit_custom', '/support_new', 'reply_ticket_', 'enter_discount_'])) {
            $user->update(['bot_state' => null]);
        }

        if (Str::startsWith($data, 'select_loc_')) {
            $parts = explode('_', $data);

            if (count($parts) >= 5) {
                $locationId = $parts[2];
                $planId = $parts[4];


                $location = \Modules\MultiServer\Models\Location::find($locationId);
                if ($location) {
                    $totalCapacity = $location->servers()->where('is_active', true)->sum('capacity');
                    $totalUsed = $location->servers()->where('is_active', true)->sum('current_users');


                    if ($totalUsed >= $totalCapacity) {
                        $settings = \App\Models\Setting::all()->pluck('value', 'key');

                        $msg = $settings->get('ms_full_location_message') ?? "❌ ظرفیت تکمیل است.";


                        Telegram::answerCallbackQuery([
                            'callback_query_id' => $callbackQuery->getId(),
                            'text' => $msg,
                            'show_alert' => true
                        ]);
                        return;
                    }
                }

                $user->update([
                    'bot_state' => "selected_loc:{$locationId}|plan:{$planId}"
                ]);

                $this->promptForUsername($user, $planId, $messageId);
                return;
            }
        }

        if (Str::startsWith($data, 'buy_plan_')) {
            $planId = Str::after($data, 'buy_plan_');


            if (class_exists('Modules\MultiServer\Models\Location')) {
                $this->promptForLocation($user, $planId, $messageId);
                return;
            }


            $this->promptForUsername($user, $planId, $messageId);
            return;
        }

        elseif (Str::startsWith($data, 'pay_wallet_')) {
            $input = Str::after($data, 'pay_wallet_');
            $this->processWalletPayment($user, $input, $messageId);
        } elseif (Str::startsWith($data, 'pay_card_')) {
            $orderId = Str::after($data, 'pay_card_');
            $this->sendCardPaymentInfo($chatId, $orderId, $messageId);
        } elseif (Str::startsWith($data, 'pay_plisio_')) {
            $orderId = (int) Str::after($data, 'pay_plisio_');
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {
                Log::warning('answerCallbackQuery pay_plisio: '.$e->getMessage());
            }
            $this->startPlisioPayment($user, $orderId, $messageId);
        } elseif (Str::startsWith($data, 'deposit_card_')) {
            $orderId = Str::after($data, 'deposit_card_');
            $ord = Order::find($orderId);
            if ($ord && $ord->user_id === $user->id) {
                try {
                    Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
                } catch (\Exception $e) {
                }
                $this->sendCardPaymentInfo($chatId, $orderId, $messageId);
            }
        } elseif (Str::startsWith($data, 'deposit_plisio_')) {
            $orderId = (int) Str::after($data, 'deposit_plisio_');
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {
            }
            $this->startPlisioPayment($user, $orderId, $messageId);
        } elseif (Str::startsWith($data, 'enter_discount_')) {
            $orderId = Str::after($data, 'enter_discount_');
            $this->promptForDiscount($user, $orderId, $messageId);
        } elseif (Str::startsWith($data, 'remove_discount_')) {
            $orderId = Str::after($data, 'remove_discount_');
            $this->removeDiscount($user, $orderId, $messageId);
        } elseif (Str::startsWith($data, 'copy_link_')) {
            $encodedLink = Str::after($data, 'copy_link_');
            $linkToCopy = base64_decode($encodedLink);
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQuery->getId(),
                    'text' => '✅ لینک در پیام بعدی ارسال شد. می‌توانید آن را کپی کنید.',
                    'show_alert' => false
                ]);
                // ارسال لینک در یک پیام جداگانه با فرمت monospace برای کپی آسان
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "`{$linkToCopy}`",
                    'parse_mode' => 'MarkdownV2'
                ]);
            } catch (\Exception $e) {
                Log::warning('Could not handle copy link: ' . $e->getMessage());
                try {
                    Telegram::answerCallbackQuery([
                        'callback_query_id' => $callbackQuery->getId(),
                        'text' => '❌ خطا در ارسال لینک. لطفاً از پیام قبلی کپی کنید.',
                        'show_alert' => true
                    ]);
                } catch (\Exception $e2) {
                    Log::error('Could not answer callback query: ' . $e2->getMessage());
                }
            }
        } elseif (Str::startsWith($data, 'qrcode_order_')) {
            $orderId = Str::after($data, 'qrcode_order_');
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {
                Log::warning('Could not answer callback query for QR Code: ' . $e->getMessage());
            }
            $this->sendQRCodeForOrder($user, $orderId);
        } elseif (Str::startsWith($data, 'renew_order_')) {
            $originalOrderId = Str::after($data, 'renew_order_');
            $this->startRenewalPurchaseProcess($user, $originalOrderId, $messageId);
        } elseif (Str::startsWith($data, 'renew_pay_wallet_')) {
            $originalOrderId = Str::after($data, 'renew_pay_wallet_');
            $this->processRenewalWalletPayment($user, $originalOrderId, $messageId);
        } elseif (Str::startsWith($data, 'renew_pay_card_')) {
            $originalOrderId = Str::after($data, 'renew_pay_card_');
            $this->handleRenewCardPayment($user, $originalOrderId, $messageId);
        } elseif (Str::startsWith($data, 'renew_pay_plisio_')) {
            $originalOrderId = Str::after($data, 'renew_pay_plisio_');
            try {
                Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
            } catch (\Exception $e) {
            }
            $this->handleRenewPlisioPayment($user, $originalOrderId, $messageId);
        } elseif (Str::startsWith($data, 'deposit_amount_')) {
            $amount = Str::after($data, 'deposit_amount_');
            $this->processDepositAmount($user, $amount, $messageId);
        } elseif ($data === '/deposit_custom') {
            $this->promptForCustomDeposit($user, $messageId);
        } elseif (Str::startsWith($data, 'close_ticket_')) {
            $ticketId = Str::after($data, 'close_ticket_');
            $this->closeTicket($user, $ticketId, $messageId, $callbackQuery->getId());
        } elseif (Str::startsWith($data, 'reply_ticket_')) {
            $ticketId = Str::after($data, 'reply_ticket_');
            $this->promptForTicketReply($user, $ticketId, $messageId);
        } elseif ($data === '/support_new') {
            $this->promptForNewTicket($user, $messageId);
        } else {
            switch ($data) {
                case '/start':
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => '🌟 منوی اصلی',
                        'reply_markup' => $this->getReplyMainMenu()
                    ]);
                    try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                    break;
                case '/plans': $this->sendPlans($chatId, $messageId); break;
                case '/my_services': $this->sendMyServices($user, $messageId); break;
                case '/wallet': $this->sendWalletMenu($user, $messageId); break;
                case '/referral': $this->sendReferralMenu($user, $messageId); break;
                case '/support_menu': $this->showSupportMenu($user, $messageId); break;
                case '/deposit': $this->showDepositOptions($user, $messageId); break;
                case '/transactions': $this->sendTransactions($user, $messageId); break;
                case '/tutorials': $this->sendTutorialsMenu($chatId, $messageId); break;
                case '/tutorial_android': $this->sendTutorial('android', $chatId, $messageId); break;
                case '/tutorial_ios': $this->sendTutorial('ios', $chatId, $messageId); break;
                case '/tutorial_windows': $this->sendTutorial('windows', $chatId, $messageId); break;
                case '/check_membership':
                    if ($this->isUserMemberOfChannel($user)) {
                        Telegram::answerCallbackQuery([
                            'callback_query_id' => $callbackQuery->getId(),
                            'text' => 'عضویت شما تأیید شد!',
                            'show_alert' => false
                        ]);
                        try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                        Telegram::sendMessage([
                            'chat_id' => $chatId,
                            'text' => 'خوش آمدید! حالا می‌توانید از ربات استفاده کنید.',
                            'reply_markup' => $this->getReplyMainMenu()
                        ]);
                    } else {
                        Telegram::answerCallbackQuery([
                            'callback_query_id' => $callbackQuery->getId(),
                            'text' => 'هنوز عضو کانال نشده‌اید. لطفاً اول عضو شوید.',
                            'show_alert' => true
                        ]);
                        $this->showChannelRequiredMessage($chatId, $messageId);
                    }
                    break;

                case '🧪 اکانت تست':
                    try {
                        Telegram::answerCallbackQuery(['callback_query_id' => $callbackQuery->getId()]);
                    } catch (\Exception $e) {
                        Log::warning('Could not answer callback query: ' . $e->getMessage());
                    }
                    $this->handleTrialRequest($user);
                    try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                    break;

                case '/cancel_action':
                    $user->update(['bot_state' => null]);
                    try { Telegram::deleteMessage(['chat_id' => $chatId, 'message_id' => $messageId]); } catch (\Exception $e) {}
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => '✅ عملیات لغو شد.',
                        'reply_markup' => $this->getReplyMainMenu(),
                    ]);
                    break;
                default:
                    Log::warning('Unknown callback data received:', ['data' => $data, 'chat_id' => $chatId]);
                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'دستور نامعتبر.',
                        'reply_markup' => $this->getReplyMainMenu(),
                    ]);
                    break;
            }
        }
    }


    protected function promptForLocation($user, $planId, $messageId)
    {

        $settings = \App\Models\Setting::all()->pluck('value', 'key');
        $showCapacity = filter_var($settings->get('ms_show_capacity', true), FILTER_VALIDATE_BOOLEAN);
        $hideFull = filter_var($settings->get('ms_hide_full_locations', false), FILTER_VALIDATE_BOOLEAN);

        $locations = \Modules\MultiServer\Models\Location::where('is_active', true)->with('servers')->get();

        $keyboard = Keyboard::make()->inline();
        $hasAvailableLocation = false;

        foreach ($locations as $loc) {

            $totalCapacity = $loc->servers->where('is_active', true)->sum('capacity');
            $totalUsed = $loc->servers->where('is_active', true)->sum('current_users');
            $remained = max(0, $totalCapacity - $totalUsed);
            $isFull = $remained <= 0;


            if ($isFull && $hideFull) {
                continue;
            }

            $hasAvailableLocation = true;
            $flag = $loc->flag ?? '🏳️';


            $btnText = "$flag {$loc->name}";

            if ($isFull) {
                $btnText .= " (تکمیل 🔒)";
            } elseif ($showCapacity) {
                $btnText .= " ({$remained} عدد)";
            }

            $keyboard->row([
                Keyboard::inlineButton([
                    'text' => $btnText,
                    // حتی اگر پر باشد، دکمه کار می‌کند تا پیام سفارشی را نشان دهد
                    'callback_data' => "select_loc_{$loc->id}_plan_{$planId}"
                ])
            ]);
        }

        if (!$hasAvailableLocation) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ متأسفانه ظرفیت تمام سرورها تکمیل شده است."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, "🌍 *انتخاب لوکیشن*\n\nلطفاً کشور مورد نظر خود را انتخاب کنید:", $keyboard, $messageId);
    }

    protected function handlePhotoMessage($update)
    {
        $message = $update->getMessage();
        $chatId = $message->getChat()->getId();
        $user = User::where('telegram_chat_id', $chatId)->first();

        if ($user && !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId);
            return;
        }

        if (!$user || !$this->isUserMemberOfChannel($user)) {
            $this->showChannelRequiredMessage($chatId);
            return;
        }

        if (!$user || !$user->bot_state) {
            $this->sendOrEditMainMenu($chatId, "❌ لطفاً ابتدا یک عملیات (مانند ثبت تیکت یا رسید) را شروع کنید.");
            return;
        }

        if (Str::startsWith($user->bot_state, 'awaiting_ticket_reply|') || Str::startsWith($user->bot_state, 'awaiting_new_ticket_message|')) {
            $text = $message->getCaption() ?? '[📎 فایل پیوست شد]';
            $this->processTicketConversation($user, $text, $update);
            return;
        }

        if (Str::startsWith($user->bot_state, 'waiting_receipt_')) {
            $orderId = Str::after($user->bot_state, 'waiting_receipt_');
            $order = Order::find($orderId);

            if ($order && $order->user_id === $user->id && $order->status === 'pending') {
                try {
                    $fileName = $this->savePhotoAttachment($update, 'receipts');
                    if (!$fileName) throw new \Exception("Failed to save photo attachment.");

                    $order->update(['card_payment_receipt' => $fileName]);
                    $user->update(['bot_state' => null]);

                    Telegram::sendMessage([
                        'chat_id' => $chatId,
                        'text' => $this->escape("✅ رسید شما با موفقیت ثبت شد. پس از بررسی توسط ادمین، نتیجه به شما اطلاع داده خواهد شد."),
                        'parse_mode' => 'MarkdownV2',
                    ]);
                    $this->sendOrEditMainMenu($chatId, "چه کار دیگری برایتان انجام دهم?");

                    $adminChatId = $this->settings->get('telegram_admin_chat_id');
                    if ($adminChatId) {
                        $orderType = $order->renews_order_id ? 'تمدید سرویس' : ($order->plan_id ? 'خرید سرویس' : 'شارژ کیف پول');

                        $adminMessage = "🧾 *رسید جدید برای سفارش \\#{$orderId}*\n\n";
                        $adminMessage .= "*کاربر:* " . $this->escape($user->name) . " \\(ID: `{$user->id}`\\)\n";
                        $adminMessage .= "*مبلغ:* " . $this->escape(number_format($order->amount) . ' تومان') . "\n";
                        $adminMessage .= "*نوع سفارش:* " . $this->escape($orderType) . "\n\n";
                        $adminMessage .= $this->escape("لطفا در پنل مدیریت بررسی و تایید کنید.");

                        Telegram::sendPhoto([
                            'chat_id' => $adminChatId,
                            'photo' => InputFile::create(Storage::disk('public')->path($fileName)),
                            'caption' => $adminMessage,
                            'parse_mode' => 'MarkdownV2'
                        ]);
                    }

                } catch (\Exception $e) {
                    Log::error("Receipt processing failed for order {$orderId}: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ خطا در پردازش رسید. لطفاً دوباره تلاش کنید."), 'parse_mode' => 'MarkdownV2']);
                    $this->sendOrEditMainMenu($chatId, "لطفا دوباره تلاش کنید.");
                }
            } else {
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ سفارش نامعتبر است یا در انتظار پرداخت نیست."), 'parse_mode' => 'MarkdownV2']);
                $this->sendOrEditMainMenu($chatId, "لطفا وضعیت سفارش خود را بررسی کنید.");
            }
        }
    }

    // ========================================================================
    // 🛒 سیستم خرید و تخفیف
    // ========================================================================

    protected function startPurchaseProcess($user, $planId, $username, $messageId = null)
    {
        $plan = Plan::find($planId);
        if (!$plan) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ پلن مورد نظر یافت نشد.", $messageId);
            return;
        }

        $serverId = null;


        if (class_exists('Modules\MultiServer\Models\Server') && $user->bot_state && Str::contains($user->bot_state, 'selected_loc:')) {
            $stateParts = explode('|', $user->bot_state);
            $locPart = $stateParts[0];
            $locationId = Str::after($locPart, ':');

            $bestServer = \Modules\MultiServer\Models\Server::where('location_id', $locationId)
                ->where('is_active', true)
                ->whereRaw('current_users < capacity')
                ->orderBy('current_users', 'asc')
                ->first();

            if ($bestServer) {
                $serverId = $bestServer->id;
            } else {
                $user->update(['bot_state' => null]);
                Telegram::sendMessage([
                    'chat_id' => $user->telegram_chat_id,
                    'text' => $this->escape("❌ متأسفانه ظرفیت سرورهای این لوکیشن تکمیل شده است."),
                    'parse_mode' => 'MarkdownV2'
                ]);
                return;
            }
        }

        $order = $user->orders()->create([
            'plan_id' => $plan->id,
            'server_id' => $serverId,
            'status' => 'pending',
            'source' => 'telegram',
            'amount' => $plan->price,
            'discount_amount' => 0,
            'discount_code_id' => null,
            'panel_username' => $username
        ]);

        $user->update(['bot_state' => null]);
        $this->showInvoice($user, $order, $messageId);
    }

    protected function showInvoice($user, Order $order, $messageId = null)
    {
        $plan = $order->plan;
        $balance = $user->balance ?? 0;

        $message = "🛒 *تایید خرید*\n\n";
        $message .= "▫️ پلن: *{$this->escape($plan->name)}*\n";

        if ($order->discount_amount > 0) {
            $originalPrice = number_format($plan->price);
            $finalPrice = number_format($order->amount);
            $discount = number_format($order->discount_amount);
            $message .= "▫️ قیمت اصلی: ~*{$originalPrice} تومان*~\n";
            $message .= "🎉 *قیمت با تخفیف:* *{$finalPrice} تومان*\n";
            $message .= "💰 سود شما: *{$discount} تومان*\n";
        } else {
            $message .= "▫️ قیمت: *" . number_format($order->amount) . " تومان*\n";
        }

        $message .= "▫️ موجودی کیف پول: *" . number_format($balance) . " تومان*\n\n";
        $message .= "لطفاً روش پرداخت را انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();

        if (!$order->discount_code_id) {
            $keyboard->row([Keyboard::inlineButton(['text' => '🎫 ثبت کد تخفیف', 'callback_data' => "enter_discount_{$order->id}"])]);
        } else {
            $keyboard->row([Keyboard::inlineButton(['text' => '❌ حذف کد تخفیف', 'callback_data' => "remove_discount_{$order->id}"])]);
        }

        if ($balance >= $order->amount) {
            $keyboard->row([Keyboard::inlineButton(['text' => '✅ پرداخت با کیف پول', 'callback_data' => "pay_wallet_order_{$order->id}"])]);
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '💳 کارت به کارت', 'callback_data' => "pay_card_{$order->id}"])]);
        if ($this->isPlisioActive()) {
            $keyboard->row([Keyboard::inlineButton(['text' => '💎 پرداخت Plisio (کریپتو)', 'callback_data' => "pay_plisio_{$order->id}"])]);
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به پلن‌ها', 'callback_data' => '/plans'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function promptForDiscount($user, $orderId, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_discount_code|' . $orderId]);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, "🎫 لطفاً کد تخفیف خود را ارسال کنید:", $keyboard, $messageId);
    }

    protected function processDiscountCode($user, $orderId, $codeText)
    {
        $order = Order::find($orderId);
        if (!$order || $order->status !== 'pending') {
            $user->update(['bot_state' => null]);
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سفارش منقضی شده است.");
            return;
        }

        $code = DiscountCode::where('code', $codeText)->first();
        $error = null;

        if (!$code) $error = '❌ کد تخفیف نامعتبر است.';
        elseif (!$code->is_active) $error = '❌ کد تخفیف غیرفعال است.';
        elseif ($code->starts_at && $code->starts_at > now()) $error = '❌ زمان استفاده از کد نرسیده است.';
        elseif ($code->expires_at && $code->expires_at < now()) $error = '❌ کد تخفیف منقضی شده است.';
        else {
            $totalAmount = $order->plan_id ? $order->plan->price : $order->amount;
            if (!$code->isValidForOrder($totalAmount, $order->plan_id, !$order->plan_id, (bool)$order->renews_order_id)) {
                $error = '❌ کد تخفیف شامل شرایط این سفارش نمی‌شود.';
            }
        }

        if ($error) {
            Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $this->escape($error), 'parse_mode' => 'MarkdownV2']);
            return;
        }

        $discountAmount = $code->calculateDiscount($order->plan->price ?? $order->amount);
        $finalAmount = ($order->plan->price ?? $order->amount) - $discountAmount;

        $order->update([
            'discount_amount' => $discountAmount,
            'discount_code_id' => $code->id,
            'amount' => $finalAmount
        ]);

        $user->update(['bot_state' => null]);
        Telegram::sendMessage(['chat_id' => $user->telegram_chat_id, 'text' => $this->escape("✅ کد تخفیف اعمال شد!"), 'parse_mode' => 'MarkdownV2']);
        $this->showInvoice($user, $order);
    }

    protected function removeDiscount($user, $orderId, $messageId)
    {
        $order = Order::find($orderId);
        if ($order && $order->status === 'pending') {
            $originalPrice = $order->plan->price ?? ($order->amount + $order->discount_amount);
            $order->update([
                'discount_amount' => 0,
                'discount_code_id' => null,
                'amount' => $originalPrice
            ]);
            $this->showInvoice($user, $order, $messageId);
        }
    }

    protected function processWalletPayment($user, $input, $messageId)
    {
        $order = null;
        $plan = null;

        if (Str::startsWith($input, 'order_')) {
            $orderId = Str::after($input, 'order_');
            $order = Order::find($orderId);
            if (!$order || $order->user_id !== $user->id || $order->status !== 'pending') {
                $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سفارش نامعتبر.", $messageId);
                return;
            }
            $plan = $order->plan;
        } else {
            $planId = $input;
            $plan = Plan::find($planId);
            if (!$plan) return;
            $order = $user->orders()->create([
                'plan_id' => $plan->id, 'status' => 'pending', 'source' => 'telegram', 'amount' => $plan->price
            ]);
        }

        if ($user->balance < $order->amount) {
            $this->sendOrEditMessage($user->telegram_chat_id, "❌ موجودی کیف پول کافی نیست.", Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => '/deposit'])]), $messageId);
            return;
        }

        try {
            DB::transaction(function () use ($user, $plan, &$order) {
                $user->decrement('balance', $order->amount);

                $order->update([
                    'status' => 'paid',
                    'payment_method' => 'wallet',
                    'expires_at' => now()->addDays($plan->duration_days)
                ]);

                if ($order->discount_code_id) {
                    $dc = DiscountCode::find($order->discount_code_id);
                    if ($dc) {
                        DiscountCodeUsage::create([
                            'discount_code_id' => $dc->id,
                            'user_id' => $user->id,
                            'order_id' => $order->id,
                            'discount_amount' => $order->discount_amount,
                            'original_amount' => $plan->price
                        ]);
                        $dc->increment('used_count');
                    }
                }

                Transaction::create([
                    'user_id' => $user->id, 'order_id' => $order->id, 'amount' => -$order->amount,
                    'type' => 'purchase', 'status' => 'completed',
                    'description' => "خرید سرویس {$plan->name}"
                ]);

                $provisionData = $this->provisionUserAccount($order, $plan);

                if ($provisionData && $provisionData['link']) {
                    $order->update([
                        'config_details' => $provisionData['link'],
                        'panel_username' => $provisionData['username']
                    ]);
                } else {
                    throw new \Exception('Provisioning failed, config data is null.');
                }
            });

            $order->refresh();
            $link = $order->config_details;

            $this->sendOrEditMessage($user->telegram_chat_id, "✅ خرید موفق!\n\nلینک کانفیگ:\n{$link}", Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services']), Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])]), $messageId);
        } catch (\Exception $e) {
            Log::error('Wallet Payment Failed: ' . $e->getMessage(), ['trace' => $e->getTraceAsString(), 'plan_id' => $plan->id ?? null, 'user_id' => $user->id]);
            if ($order && $order->exists) {
                $order->update(['status' => 'failed']);
                try {
                    $user->increment('balance', $order->amount);
                    Log::info("User balance refunded after failed provisioning.", ['user_id' => $user->id, 'amount' => $order->amount]);
                } catch (\Exception $refundEx) {
                    Log::critical("CRITICAL: Failed to refund user balance!", ['user_id' => $user->id, 'amount' => $order->amount, 'error' => $refundEx->getMessage()]);
                }
            }
            $orderIdText = $order ? "\\#{$order->id}" : '';
            $this->sendOrEditMessage($user->telegram_chat_id, "⚠️ پرداخت موفق بود اما در ایجاد سرویس خطایی رخ داد. مبلغ به کیف پول شما بازگردانده شد. لطفاً با پشتیبانی تماس بگیرید. سفارش: {$orderIdText}", Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '💬 پشتیبانی', 'callback_data' => '/support_menu'])]), $messageId);
        }
    }

    protected function sendCardPaymentInfo($chatId, $orderId, $messageId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            $this->sendOrEditMainMenu($chatId, "❌ سفارش یافت نشد.", $messageId);
            return;
        }
        $user = $order->user;
        $user->update(['bot_state' => 'waiting_receipt_' . $orderId]);

        $cardNumber = $this->settings->get('payment_card_number', 'شماره کارتی تنظیم نشده');
        $cardHolder = $this->settings->get('payment_card_holder_name', 'صاحب حسابی تنظیم نشده');
        $amountToPay = number_format($order->amount);

        $message = "💳 *پرداخت کارت به کارت*\n\n";
        $message .= "لطفاً مبلغ *" . $this->escape($amountToPay) . " تومان* را به حساب زیر واریز نمایید:\n\n";
        $message .= "👤 *به نام:* " . $this->escape($cardHolder) . "\n";
        $message .= "💳 *شماره کارت:*\n`" . $this->escape($cardNumber) . "`\n\n";
        $message .= "🔔 *مهم:* پس از واریز، *فقط عکس رسید* را در همین چت ارسال کنید\\.";

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف از پرداخت', 'callback_data' => '/cancel_action'])]);

        $this->sendRawMarkdownMessage($chatId, $message, $keyboard, $messageId);
    }

    // ========================================================================
    // سایر متدها (پلان‌ها، تمدید، تیکت، آموزش و ...)
    // ========================================================================

    protected function sendPlans($chatId, $messageId = null)
    {
        try {
            $activePlans = Plan::where('is_active', true)
                ->orderBy('duration_days', 'asc')
                ->get();

            if ($activePlans->isEmpty()) {
                $message = "اگر نیاز به سرویس های VPN دارید به یکی از کانال های ما مراجعه کنید.\n\n";
                $message .= "@BypassInternet\n";
                $message .= "@V2Raydar\n";
                $message .= "@NeptuneVpn\n";
                $message .= "@Age_Of_Filtering\n\n";
                $message .= "برای تهیه VPN رایگان (حتی نت ملی) فقط از همین ربات روی دکمه \"🧪اکانت تست \" بزنید.";
                
                $keyboard = Keyboard::make()->inline()
                    ->row([
                        Keyboard::inlineButton(['text' => '🧪 اکانت تست', 'callback_data' => '🧪 اکانت تست']),
                        Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/start'])
                    ]);
                $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
                return;
            }

            $durations = $activePlans->pluck('duration_days')->unique()->sort();

            $message = "🚀 *انتخاب سرویس VPN*\n\n";
            $message .= "لطفاً مدت‌زمان سرویس مورد نظر را انتخاب کنید:\n\n";
            $message .= "👇 یکی از گزینه‌های زیر را بزنید:";

            $keyboard = Keyboard::make()->inline();

            foreach ($durations as $durationDays) {
                $buttonText = $this->generateDurationLabel($durationDays);
                $keyboard->row([
                    Keyboard::inlineButton([
                        'text' => $buttonText,
                        'callback_data' => "show_duration_{$durationDays}"
                    ])
                ]);
            }

            $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);

        } catch (\Exception $e) {
            Log::error('Error in sendPlans: ' . $e->getMessage(), [
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString()
            ]);

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, "❌ خطایی در بارگذاری پلن‌ها رخ داد.", $keyboard, $messageId);
        }
    }

    protected function generateDurationLabel(int $days): string
    {
        if ($days % 30 === 0) {
            $months = $days / 30;
            return match ($months) {
                1 => '🔸 یک ماهه',
                2 => '🔸 دو ماهه',
                3 => '🔸 سه ماهه',
                6 => '🔸 شش ماهه',
                12 => '🔸 یک ساله',
                default => "{$months} ماهه",
            };
        }
        return "{$days} روزه";
    }

    protected function sendPlansByDuration($chatId, $durationDays, $messageId = null)
    {
        try {
            $plans = Plan::where('is_active', true)
                ->where('duration_days', $durationDays)
                ->orderBy('volume_gb', 'asc')
                ->get();

            if ($plans->isEmpty()) {
                $keyboard = Keyboard::make()->inline()
                    ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/plans'])]);
                $this->sendOrEditMessage($chatId, "⚠️ پلنی با این مدت‌زمان یافت نشد.", $keyboard, $messageId);
                return;
            }

            $durationLabel = $plans->first()->duration_label;
            $message = "📅 *پلن‌های {$durationLabel}*\n\n";

            foreach ($plans as $index => $plan) {
                if ($index > 0) {
                    $message .= "〰️〰️〰️\n\n";
                }
                $message .= ($index + 1) . ". 💎 *" . $this->escape($plan->name) . "*\n";
                $message .= "   📦 " . $this->escape($plan->volume_gb . ' گیگ') . "\n";
                $message .= "   💳 " . $this->escape(number_format($plan->price) . ' تومان') . "\n";
            }

            $message .= "\n👇 پلن مورد نظر را انتخاب کنید:";

            $keyboard = Keyboard::make()->inline();

            foreach ($plans as $plan) {
                $buttonText = $this->escape($plan->name) . ' | ' . number_format($plan->price) . ' تومان';
                $keyboard->row([
                    Keyboard::inlineButton([
                        'text' => $buttonText,
                        'callback_data' => "buy_plan_{$plan->id}"
                    ])
                ]);
            }

            $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به انتخاب زمان', 'callback_data' => '/plans'])]);

            $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);

        } catch (\Exception $e) {
            Log::error('Error in sendPlansByDuration: ' . $e->getMessage(), [
                'duration_days' => $durationDays,
                'chat_id' => $chatId,
                'trace' => $e->getTraceAsString()
            ]);

            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

            $this->sendOrEditMessage($chatId, "❌ خطایی در بارگذاری پلن‌ها رخ داد.", $keyboard, $messageId);
        }
    }

    protected function sendQRCodeForOrder($user, $orderId)
    {
        $order = $user->orders()->find($orderId);
        if (!$order) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ سرویس یافت نشد."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        if (empty($order->config_details)) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ لینک کانفیگ هنوز آماده نشده است."),
                'parse_mode' => 'MarkdownV2'
            ]);
            return;
        }

        try {

            $configLink = trim($order->config_details);

            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query([
                    'size' => '400x400',
                    'data' => $configLink,
                    'ecc' => 'M',
                    'margin' => 10,
                    'color' => '000000',
                    'bgcolor' => 'FFFFFF',
                    'format' => 'png'
                ]);

            $ch = curl_init($qrUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $qrData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($qrData === false || $httpCode !== 200) {
                throw new \Exception("دریافت QR Code ناموفق بود. کد: {$httpCode} - خطا: {$curlError}");
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'qr_') . '.png';
            file_put_contents($tempFile, $qrData);

            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton(['text' => "🔄 تمدید سرویس", 'callback_data' => "renew_order_{$order->id}"]),
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت به جزئیات', 'callback_data' => "show_service_{$order->id}"])
                ])
                ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به لیست سرویس‌ها', 'callback_data' => '/my_services'])]);

            Telegram::sendPhoto([
                'chat_id' => $user->telegram_chat_id,
                'photo' => InputFile::create($tempFile),
                'caption' => $this->escape("📱 QR Code برای سرویس #{$order->id}\n\nلینک: {$configLink}"),
                'parse_mode' => 'MarkdownV2',
                'reply_markup' => $keyboard
            ]);

            @unlink($tempFile);

        } catch (\Exception $e) {
            Log::error('QR Code Generation FAILED', [
                'order_id' => $orderId,
                'user_id' => $user->id,
                'error_message' => $e->getMessage(),
                'config' => $order->config_details ?? 'N/A'
            ]);

            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ خطا در تولید QR Code. لطفاً لینک را کپی کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }
    protected function sendMyServices($user, $messageId = null)
    {
        $orders = $user->orders()->with('plan')
            ->where('status', 'paid')
            ->whereNotNull('plan_id')
            ->whereNull('renews_order_id')
            ->where('expires_at', '>', now()->subDays(30))
            ->orderBy('expires_at', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            $keyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '🛒 خرید سرویس جدید', 'callback_data' => '/plans']),
                Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start']),
            ]);
            $this->sendOrEditMessage($user->telegram_chat_id, "⚠️ شما هیچ سرویس فعال یا اخیراً منقضی شده‌ای ندارید.", $keyboard, $messageId);
            return;
        }

        $message = "🛠 *سرویس‌های شما*\n\nلطفاً یک سرویس را برای مشاهده جزئیات انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();

        foreach ($orders as $order) {
            if (!$order->plan) {
                continue;
            }

            $expiresAt = Carbon::parse($order->expires_at);
            $now = now();
            $statusIcon = '🟢';

            if ($expiresAt->isPast()) {
                $statusIcon = '⚫️';
            } elseif ($expiresAt->diffInDays($now) <= 7) {
                $statusIcon = '🟡';
            }

            $username = $order->panel_username ?: "سرویس-{$order->id}";
            $buttonText = "{$statusIcon} {$username} (ID: #{$order->id})";

            $keyboard->row([
                Keyboard::inlineButton([
                    'text' => $buttonText,
                    'callback_data' => "show_service_{$order->id}"
                ])
            ]);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function showServiceDetails($user, $orderId, $messageId = null)
    {
        $order = $user->orders()->with('plan')->find($orderId);

        if (!$order || !$order->plan || $order->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر یافت نشد یا معتبر نیست.", $messageId);
            return;
        }

        $panelUsername = $order->panel_username;
        if (empty($panelUsername)) {
            $panelUsername = "user-{$user->id}-order-{$order->id}";
        }

        $expiresAt = Carbon::parse($order->expires_at);
        $now = now();
        $statusIcon = '🟢';

        $daysRemaining = $now->diffInDays($expiresAt, false);
        $daysRemaining = (int) $daysRemaining;

        if ($expiresAt->isPast()) {
            $statusIcon = '⚫️';
            $remainingText = "*منقضی شده*";
        } elseif ($daysRemaining <= 7) {
            $statusIcon = '🟡';
            $remainingText = "*" . $this->escape($daysRemaining . ' روز') . "* باقی‌مانده (تمدید کنید)";
        } else {
            $remainingText = "*" . $this->escape($daysRemaining . ' روز') . "* باقی‌مانده";
        }

        $message = "🔍 جزئیات سرویس #{$order->id}\n\n";
        $message .= "{$statusIcon} سرویس: " . $this->escape($order->plan->name) . "\n";
        $message .= "👤 نام کاربری: `" . $panelUsername . "`\n";
        $message .= "🗓 انقضا: " . $this->escape($expiresAt->format('Y/m/d')) . " - " . $remainingText . "\n";
        $message .= "📦  حجم:  " . $this->escape($order->plan->volume_gb . ' گیگابایت') . "\n";
        if (!empty($order->config_details)) {
            $message .= "\n🔗 *لینک اتصال:*\n" . $order->config_details;
        } else {
            $message .= "\n⏳ *در حال آماده‌سازی کانفیگ...*";
        }

        $keyboard = Keyboard::make()->inline();

        if (!empty($order->config_details)) {
            $keyboard->row([
                Keyboard::inlineButton(['text' => "📱 دریافت QR Code", 'callback_data' => "qrcode_order_{$order->id}"])
            ]);
        }

        $keyboard->row([
            Keyboard::inlineButton(['text' => "🔄 تمدید سرویس", 'callback_data' => "renew_order_{$order->id}"])
        ]);

        $keyboard->row([
            Keyboard::inlineButton(['text' => '⬅️ بازگشت به لیست سرویس‌ها', 'callback_data' => '/my_services'])
        ]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function sendWalletMenu($user, $messageId = null)
    {
        $balance = number_format($user->balance ?? 0);
        $message = "💰 *کیف پول شما*\n\n";
        $message .= "موجودی فعلی: *{$balance} تومان*\n\n";
        $message .= "می‌توانید حساب خود را شارژ کنید یا تاریخچه تراکنش‌ها را مشاهده نمایید:";

        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '💳 شارژ حساب', 'callback_data' => '/deposit']),
                Keyboard::inlineButton(['text' => '📜 تاریخچه تراکنش‌ها', 'callback_data' => '/transactions']),
            ])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function sendReferralMenu($user, $messageId = null)
    {
        try {
            $botInfo = Telegram::getMe();
            $botUsername = $botInfo->getUsername();
        } catch (\Exception $e) {
            Log::error("Could not get bot username: " . $e->getMessage());
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ خطایی در دریافت اطلاعات ربات رخ داد.", $messageId);
            return;
        }

        $referralCode = $user->referral_code ?? Str::random(8);
        if (!$user->referral_code) {
            $user->update(['referral_code' => $referralCode]);
        }
        $referralLink = "https://t.me/{$botUsername}?start={$referralCode}";
        $referrerReward = number_format((int) $this->settings->get('referral_referrer_reward', 0));
        $referralCount = $user->referrals()->count();

        $message = "🎁 *دعوت از دوستان*\n\n";
        $message .= "با اشتراک‌گذاری لینک زیر، دوستان خود را به ربات دعوت کنید.\n\n";
        $message .= "💸 با هر خرید موفق دوستانتان، *{$referrerReward} تومان* به کیف پول شما اضافه می‌شود.\n\n";
        $message .= "🔗 *لینک دعوت شما:*\n`{$referralLink}`\n\n";
        $message .= "👥 تعداد دعوت‌های موفق شما: *{$referralCount} نفر*";

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function sendTransactions($user, $messageId = null)
    {
        $transactions = $user->transactions()->with('order.plan')->latest()->take(10)->get();

        $message = "📜 *۱۰ تراکنش اخیر شما*\n\n";

        if ($transactions->isEmpty()) {
            $message .= $this->escape("شما تاکنون هیچ تراکنشی ثبت نکرده‌اید.");
        } else {
            foreach ($transactions as $transaction) {
                $type = 'نامشخص';
                switch ($transaction->type) {
                    case 'deposit':
                        $type = '💰 شارژ کیف پول';
                        break;
                    case 'purchase':
                        if ($transaction->order?->renews_order_id) {
                            $type = '🔄 تمدید سرویس';
                        } else {
                            $type = '🛒 خرید سرویس';
                        }
                        break;
                    case 'referral_reward':
                        $type = '🎁 پاداش دعوت';
                        break;
                }

                $status = '⚪️';
                switch ($transaction->status) {
                    case 'completed':
                        $status = '✅';
                        break;
                    case 'pending':
                        $status = '⏳';
                        break;
                    case 'failed':
                        $status = '❌';
                        break;
                }

                $amount = number_format(abs($transaction->amount));
                $date = Carbon::parse($transaction->created_at)->format('Y/m/d');

                $message .= "{$status} *" . $this->escape($type) . "*\n";
                $message .= "   💸 *مبلغ:* " . $this->escape($amount . " تومان") . "\n";
                $message .= "   📅 *تاریخ:* " . $this->escape($date) . "\n";
                if ($transaction->order?->plan) {
                    $message .= "   🏷 *پلن:* " . $this->escape($transaction->order->plan->name) . "\n";
                }
                $message .= "〰️〰️〰️〰️〰️〰️\n";
            }
        }

        $keyboard = Keyboard::make()->inline()->row([
            Keyboard::inlineButton(['text' => '⬅️ بازگشت به کیف پول', 'callback_data' => '/wallet'])
        ]);

        $this->sendRawMarkdownMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function sendTutorialsMenu($chatId, $messageId = null)
    {
        $message = "📚 *راهنمای اتصال*\n\nلطفاً سیستم‌عامل خود را برای دریافت راهنما و لینک دانلود انتخاب کنید:";
        $keyboard = Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '📱 اندروید (V2rayNG)', 'callback_data' => '/tutorial_android']),
                Keyboard::inlineButton(['text' => '🍏 آیفون (V2Box)', 'callback_data' => '/tutorial_ios']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '💻 ویندوز (V2rayN)', 'callback_data' => '/tutorial_windows']),
                Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start']),
            ]);
        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function sendTutorial($platform, $chatId, $messageId = null)
    {
        $telegramSettings = \App\Models\TelegramBotSetting::pluck('value', 'key');

        $settingKey = match($platform) {
            'android' => 'tutorial_android',
            'ios' => 'tutorial_ios',
            'windows' => 'tutorial_windows',
            default => null
        };

        $message = $settingKey ? ($telegramSettings->get($settingKey) ?? "آموزشی برای این پلتفرم یافت نشد.")
            : "پلتفرم نامعتبر است.";

        if ($message === "آموزشی برای این پلتفرم یافت نشد.") {
            $fallbackTutorials = [
                'android' => "*راهنمای اندروید \\(V2rayNG\\)*\n\n1\\. برنامه V2rayNG را از [این لینک](https://github.com/2dust/v2rayNG/releases ) دانلود و نصب کنید\\.\n2\\. لینک کانفیگ را از بخش *سرویس‌های من* کپی کنید\\.\n3\\. در برنامه، روی علامت `+` بزنید و `Import config from Clipboard` را انتخاب کنید\\.\n4\\. کانفیگ اضافه شده را انتخاب و دکمه اتصال \\(V شکل\\) پایین صفحه را بزنید\\.",
                'ios' => "*راهنمای آیفون \\(V2Box\\)*\n\n1\\. برنامه V2Box را از [اپ استور](https://apps.apple.com/us/app/v2box-v2ray-client/id6446814690 ) نصب کنید\\.\n2\\. لینک کانفیگ را از بخش *سرویس‌های من* کپی کنید\\.\n3\\. در برنامه، وارد بخش `Configs` شوید، روی `+` بزنید و `Import from clipboard` را انتخاب کنید\\.\n4\\. برای اتصال، به بخش `Home` بروید و دکمه اتصال را بزنید \\(ممکن است نیاز به تایید VPN در تنظیمات گوشی باشد\\)\\.",
                'windows' => "*راهنمای ویندوز \\(V2rayN\\)*\n\n1\\. برنامه v2rayN را از [این لینک](https://github.com/2dust/v2rayN/releases ) دانلود \\(فایل `v2rayN-With-Core.zip`\\) و از حالت فشرده خارج کنید\\.\n2\\. فایل `v2rayN.exe` را اجرا کنید\\.\n3\\. لینک کانفیگ را از بخش *سرویس‌های من* کپی کنید\\.\n4\\. در برنامه V2RayN، کلیدهای `Ctrl+V` را فشار دهید تا سرور اضافه شود\\.\n5\\. روی آیکون برنامه در تسک‌بار \\(کنار ساعت\\) راست کلیک کرده، از منوی `System Proxy` گزینه `Set system proxy` را انتخاب کنید تا تیک بخورد\\.\n6\\. برای اتصال، دوباره روی آیکون راست کلیک کرده و از منوی `Servers` کانفیگ اضافه شده را انتخاب کنید\\.",
            ];
            $message = $fallbackTutorials[$platform] ?? "آموزشی برای این پلتفرم یافت نشد.";
        }

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به آموزش‌ها', 'callback_data' => '/tutorials'])]);

        $payload = [
            'chat_id'      => $chatId,
            'text'         => $message,
            'parse_mode'   => 'MarkdownV2',
            'reply_markup' => $keyboard,
            'disable_web_page_preview' => true
        ];

        try {
            if ($messageId) {
                $payload['message_id'] = $messageId;
                Telegram::editMessageText($payload);
            } else {
                Telegram::sendMessage($payload);
            }
        } catch (\Exception $e) {
            Log::warning("Could not edit/send tutorial message: " . $e->getMessage());
            if($messageId) {
                unset($payload['message_id']);
                try { Telegram::sendMessage($payload); } catch (\Exception $e2) {
                    Log::error("Failed fallback send tutorial: " . $e2->getMessage());
                }
            }
        }
    }

    protected function provisionUserAccount(Order $order, Plan $plan)
    {
        $settings = $this->settings;
        $uniqueUsername = $order->panel_username ?? "user-{$order->user_id}-order-{$order->id}";
        $configData = ['link' => null, 'username' => null];

        $isMultiServer = false;
        $panelType = $settings->get('panel_type') ?? 'marzban';

        $xuiHost = $settings->get('xui_host');
        $xuiUser = $settings->get('xui_user');
        $xuiPass = $settings->get('xui_pass');
        $inboundId = (int) $settings->get('xui_default_inbound_id');

        // ============================================================
        // 1. بررسی سیستم مولتی سرور (MultiServer Check)
        // ============================================================
        if (class_exists('Modules\MultiServer\Models\Server') && $order->server_id) {
            $targetServer = \Modules\MultiServer\Models\Server::find($order->server_id);


            if ($targetServer && $targetServer->is_active) {
                $isMultiServer = true;
                $panelType = 'xui';

                $xuiHost = $targetServer->full_host;
                $xuiUser = $targetServer->username;
                $xuiPass = $targetServer->password;
                $inboundId = $targetServer->inbound_id;

                $targetServer->increment('current_users');
                $targetServer->save();
                // -----------------------------------------------

                Log::info("🚀 Provisioning on MultiServer Location: {$targetServer->name}", [
                    'server_id' => $targetServer->id,
                    'current_users' => $targetServer->current_users
                ]);
            }
        }

        try {
            // ==========================================
            // پنل MARZBAN (فقط در حالت تک سرور)
            // ==========================================
            if ($panelType === 'marzban' && !$isMultiServer) {
                $marzban = new MarzbanService(
                    $settings->get('marzban_host'),
                    $settings->get('marzban_sudo_username'),
                    $settings->get('marzban_sudo_password'),
                    $settings->get('marzban_node_hostname')
                );
                $response = $marzban->createUser([
                    'username' => $uniqueUsername,
                    'proxies' => (object) [],
                    'expire' => $order->expires_at->timestamp,
                    'data_limit' => $plan->volume_gb * 1024 * 1024 * 1024,
                ]);

                if (!empty($response['subscription_url'])) {
                    $configData['link'] = $response['subscription_url'];
                    $configData['username'] = $uniqueUsername;
                } else {
                    Log::error('Marzban user creation failed.', ['response' => $response]);
                    return null;
                }
            }
            // ==========================================
            // پنل X-UI (SANAEI) -
            // ==========================================
            elseif ($panelType === 'xui') {

                if ($inboundId <= 0) {
                    throw new \Exception("Inbound ID نامعتبر است (تنظیمات یا سرور را چک کنید).");
                }


                $xui = new \App\Services\XUIService($xuiHost, $xuiUser, $xuiPass);

                if (!$xui->login()) {
                    throw new \Exception("❌ خطا در لاگین به پنل X-UI (" . ($isMultiServer ? 'MultiServer' : 'Single') . ")");
                }


                $inboundData = null;

                if ($isMultiServer) {

                    $allInbounds = $xui->getInbounds();
                    foreach ($allInbounds as $remoteInbound) {
                        if ($remoteInbound['id'] == $inboundId) {
                            $inboundData = $remoteInbound;
                            break;
                        }
                    }
                    if (!$inboundData) {
                        throw new \Exception("اینباند با ID {$inboundId} در سرور مقصد پیدا نشد.");
                    }
                } else {

                    $inboundModel = \App\Models\Inbound::whereRaw('JSON_EXTRACT(inbound_data, "$.id") = ?', [$inboundId])->first();
                    if (!$inboundModel) {

                        $allInboundsDB = \App\Models\Inbound::all();
                        foreach ($allInboundsDB as $item) {
                            $d = is_string($item->inbound_data) ? json_decode($item->inbound_data, true) : $item->inbound_data;
                            if (isset($d['id']) && $d['id'] == $inboundId) {
                                $inboundModel = $item;
                                break;
                            }
                        }
                    }

                    if ($inboundModel) {
                        $inboundData = is_string($inboundModel->inbound_data) ? json_decode($inboundModel->inbound_data, true) : $inboundModel->inbound_data;
                    } else {
                        throw new \Exception("اینباند پیش‌فرض در دیتابیس سایت یافت نشد.");
                    }
                }


                $clientData = [
                    'email' => $uniqueUsername,
                    'total' => $plan->volume_gb * 1024 * 1024 * 1024,
                    'expiryTime' => $order->expires_at->timestamp * 1000,
                ];

                $response = $xui->addClient($inboundId, $clientData);

                if ($response && isset($response['success']) && $response['success']) {


                    $linkType = $settings->get('xui_link_type', 'single');
                    $configLink = null;

                    if ($linkType === 'subscription' && !$isMultiServer) {

                        $subId = $response['generated_subId'] ?? $uniqueUsername;
                        $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');
                        if ($subBaseUrl) {
                            $configLink = $subBaseUrl . '/sub/' . $subId;
                        }
                    }


                    if (empty($configLink)) {
                        $uuid = $response['generated_uuid'] ?? $response['obj']['id'] ?? null;


                        if (!$uuid) {

                            $clients = $xui->getClients($inboundId);
                            $createdClient = collect($clients)->firstWhere('email', $uniqueUsername);
                            $uuid = $createdClient['id'] ?? null;
                        }

                        if ($uuid) {

                            $streamData = $inboundData['streamSettings'] ?? [];
                            $streamSettings = is_string($streamData) ? json_decode($streamData, true) : $streamData;

                            // تعیین آدرس و پورت سرور
                            // برای آدرس: اولویت externalProxy > listen > server_address_for_link
                            // برای پورت: همیشه از پورت اینباند استفاده می‌کنیم (نه از externalProxy.port)
                            $serverAddress = null;
                            $port = $inboundData['port'] ?? 443; // همیشه از پورت اینباند استفاده می‌کنیم
                            
                            if (!$isMultiServer) {
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
                                    $serverAddress = $settings->get('server_address_for_link', parse_url($xuiHost, PHP_URL_HOST));
                                }
                            } else {
                                $serverAddress = parse_url($xuiHost, PHP_URL_HOST);
                            }
                            $remark = $plan->name . ($isMultiServer ? " | " . $targetServer->location->name : "");


                            $protocol = $inboundData['protocol'] ?? 'vless';

                            if ($protocol === 'vless') {
                                $network = $streamSettings['network'] ?? 'tcp';
                                $params = [
                                    'type' => $network,
                                    'encryption' => 'none', // برای vless protocol همیشه none است
                                    'security' => $streamSettings['security'] ?? 'none',
                                ];

                                // استخراج پارامترها بر اساس نوع شبکه
                                if ($network === 'ws' && isset($streamSettings['wsSettings'])) {
                                    $params['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                                    $params['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? $serverAddress;
                                } elseif ($network === 'grpc' && isset($streamSettings['grpcSettings'])) {
                                    $params['path'] = $streamSettings['grpcSettings']['serviceName'] ?? null;
                                } elseif (in_array($network, ['http', 'xhttp']) && isset($streamSettings['httpSettings'])) {
                                    $httpSettings = $streamSettings['httpSettings'];
                                    $params['path'] = $httpSettings['path'] ?? '/';
                                    $params['host'] = $httpSettings['host'] ?? ($httpSettings['headers']['Host'] ?? $serverAddress);
                                    $params['mode'] = $httpSettings['mode'] ?? 'auto';
                                } elseif (in_array($network, ['http', 'xhttp']) && isset($streamSettings['xhttpSettings'])) {
                                    $xhttpSettings = $streamSettings['xhttpSettings'];
                                    $params['path'] = $xhttpSettings['path'] ?? '/';
                                    $params['host'] = $xhttpSettings['host'] ?? ($xhttpSettings['headers']['Host'] ?? $serverAddress);
                                    $params['mode'] = $xhttpSettings['mode'] ?? 'auto';
                                }
                                
                                // استخراج پارامترهای TLS
                                if (isset($streamSettings['tlsSettings']) && ($streamSettings['security'] ?? 'none') === 'tls') {
                                    $tlsSettings = $streamSettings['tlsSettings'];
                                    
                                    $params['sni'] = $tlsSettings['serverName'] ?? $serverAddress;
                                    
                                    // alpn
                                    $params['alpn'] = is_array($tlsSettings['alpn'] ?? null) 
                                        ? implode(',', $tlsSettings['alpn']) 
                                        : ($tlsSettings['alpn'] ?? null);
                                    
                                    // fingerprint و allowInsecure ممکن است در tlsSettings.settings باشند
                                    $tlsSettingsInner = $tlsSettings['settings'] ?? [];
                                    
                                    // fingerprint: اول از settings، بعد از tlsSettings مستقیم
                                    $params['fp'] = $tlsSettingsInner['fingerprint'] 
                                        ?? $tlsSettings['fingerprint'] 
                                        ?? $tlsSettings['fp'] 
                                        ?? null;
                                    
                                    // allowInsecure: اول از settings، بعد از tlsSettings مستقیم
                                    $allowInsecure = $tlsSettingsInner['allowInsecure'] 
                                        ?? $tlsSettings['allowInsecure'] 
                                        ?? false;
                                    
                                    if ($allowInsecure === true || $allowInsecure === '1' || $allowInsecure === 1 || $allowInsecure === 'true') {
                                        $params['allowInsecure'] = '1';
                                    }
                                }
                                
                                // استخراج پارامترهای Reality
                                if (isset($streamSettings['realitySettings']) && ($streamSettings['security'] ?? 'none') === 'reality') {
                                    $realitySettings = $streamSettings['realitySettings'];
                                    $realitySettingsInner = $realitySettings['settings'] ?? [];
                                    
                                    // publicKey از settings
                                    $params['pbk'] = $realitySettingsInner['publicKey'] ?? null;
                                    
                                    // fingerprint از settings
                                    $params['fp'] = $realitySettingsInner['fingerprint'] ?? null;
                                    
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
                                        $params['sni'] = $serverName;
                                    }
                                    
                                    // spiderX از settings
                                    $params['spx'] = $realitySettingsInner['spiderX'] ?? null;
                                    
                                    // shortId از shortIds (اولین مورد)
                                    if (isset($realitySettings['shortIds']) && is_array($realitySettings['shortIds']) && !empty($realitySettings['shortIds'])) {
                                        $params['sid'] = $realitySettings['shortIds'][0];
                                    }
                                }
                                
                                $queryString = http_build_query(array_filter($params));
                                $configLink = "vless://{$uuid}@{$serverAddress}:{$port}?{$queryString}#" . rawurlencode($remark);
                            }
                            elseif ($protocol === 'vmess') {

                                $vmessJson = [
                                    "v" => "2",
                                    "ps" => $remark,
                                    "add" => $serverAddress,
                                    "port" => (string)$port,
                                    "id" => $uuid,
                                    "aid" => "0",
                                    "scy" => "auto",
                                    "net" => $streamSettings['network'] ?? 'tcp',
                                    "type" => "none",
                                    "host" => "",
                                    "path" => "",
                                    "tls" => $streamSettings['security'] ?? ""
                                ];
                                if($vmessJson['net'] === 'ws') {
                                    $vmessJson['path'] = $streamSettings['wsSettings']['path'] ?? '/';
                                    $vmessJson['host'] = $streamSettings['wsSettings']['headers']['Host'] ?? $serverAddress;
                                }
                                $configLink = "vmess://" . base64_encode(json_encode($vmessJson));
                            }

                        }
                    }

                    $configData['link'] = $configLink ?: "لینک ساخته شد اما قابل نمایش نیست (خطای تولید لینک)";
                    $configData['username'] = $uniqueUsername;
                    Log::info('XUI: Client created successfully', ['link' => $configLink]);

                } else {
                    $errMsg = $response['msg'] ?? 'Unknown Error';
                    throw new \Exception("خطا در ساخت کاربر در پنل: " . $errMsg);
                }
            }
        } catch (\Exception $e) {
            Log::error("Failed to provision account for Order {$order->id}: " . $e->getMessage());


            if (isset($targetServer)) {
                $targetServer->decrement('current_users');
                $targetServer->save();
            }
            return null;
        }

        return $configData;
    }
    protected function showDepositOptions($user, $messageId)
    {
        $message = "💳 *شارژ کیف پول*\n\nلطفاً مبلغ مورد نظر برای شارژ را انتخاب کنید یا مبلغ دلخواه خود را وارد نمایید:";
        $keyboard = Keyboard::make()->inline();

        $telegramSettings = TelegramBotSetting::pluck('value', 'key');
        $depositAmountsJson = $telegramSettings->get('deposit_amounts', '[]');
        $depositAmountsData = json_decode($depositAmountsJson, true);

        $depositAmounts = [];
        if (is_array($depositAmountsData)) {
            foreach ($depositAmountsData as $item) {
                if (isset($item['amount']) && is_numeric($item['amount'])) {
                    $depositAmounts[] = (int)$item['amount'];
                }
            }
        }

        if (empty($depositAmounts)) {
            $depositAmounts = [50000, 100000, 200000, 500000];
        }

        sort($depositAmounts);

        foreach (array_chunk($depositAmounts, 2) as $row) {
            $rowButtons = [];
            foreach ($row as $amount) {
                $rowButtons[] = Keyboard::inlineButton([
                    'text' => number_format($amount) . ' تومان',
                    'callback_data' => 'deposit_amount_' . $amount
                ]);
            }
            $keyboard->row($rowButtons);
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '✍️ ورود مبلغ دلخواه', 'callback_data' => '/deposit_custom'])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به کیف پول', 'callback_data' => '/wallet'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function promptForCustomDeposit($user, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_deposit_amount']);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, "💳 لطفاً مبلغ دلخواه خود را (به تومان، حداقل ۱۰,۰۰۰) در یک پیام ارسال کنید:", $keyboard, $messageId);
    }

    protected function processDepositAmount($user, $amount, $messageId = null)
    {
        $amount = (int) preg_replace('/[^\d]/', '', $amount);
        $minDeposit = (int) $this->settings->get('min_deposit_amount', 10000);

        if ($amount < $minDeposit) {
            Telegram::sendMessage([
                'chat_id' => $user->telegram_chat_id,
                'text' => $this->escape("❌ مبلغ نامعتبر است. لطفاً مبلغی حداقل " . number_format($minDeposit) . " تومان وارد کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
            $this->promptForCustomDeposit($user, null);
            return;
        }

        $order = $user->orders()->create([
            'plan_id' => null, 'status' => 'pending', 'source' => 'telegram_deposit', 'amount' => $amount
        ]);
        $user->update(['bot_state' => null]);
        if ($this->isPlisioActive()) {
            $keyboard = Keyboard::make()->inline()
                ->row([Keyboard::inlineButton(['text' => '💳 کارت به کارت', 'callback_data' => "deposit_card_{$order->id}"])])
                ->row([Keyboard::inlineButton(['text' => '💎 پرداخت با Plisio', 'callback_data' => "deposit_plisio_{$order->id}"])])
                ->row([Keyboard::inlineButton(['text' => '⬅️ انصراف', 'callback_data' => '/wallet'])]);
            $this->sendOrEditMessage(
                $user->telegram_chat_id,
                "شارژ کیف پول به مبلغ *".number_format($amount)."* تومان ثبت شد.\n\nروش پرداخت را انتخاب کنید:",
                $keyboard,
                $messageId
            );
        } else {
            $this->sendCardPaymentInfo($user->telegram_chat_id, $order->id, $messageId);
        }
    }

    protected function sendRawMarkdownMessage($chatId, $text, $keyboard, $messageId = null, $disablePreview = false)
    {
        $payload = [
            'chat_id' => (int) $chatId,
            'text' => $text,
            'parse_mode' => 'MarkdownV2',
            'reply_markup' => $keyboard,
            'disable_web_page_preview' => $disablePreview,
        ];

        try {
            if ($messageId) {
                $payload['message_id'] = $messageId;
                Telegram::editMessageText($payload);
            } else {
                Telegram::sendMessage($payload);
            }
        } catch (\Exception $e) {
            if ($messageId && Str::contains($e->getMessage(), 'not found')) {
                unset($payload['message_id']);
                try {
                    Telegram::sendMessage($payload);
                } catch (\Exception $e2) {
                    Log::error('sendRawMarkdownMessage fallback send failed: '.$e2->getMessage());
                }
            } else {
                Log::warning('sendRawMarkdownMessage failed: '.$e->getMessage());
                unset($payload['parse_mode']);
                try {
                    if ($messageId) {
                        $payload['message_id'] = $messageId;
                        Telegram::editMessageText($payload);
                    } else {
                        Telegram::sendMessage($payload);
                    }
                } catch (\Exception $e2) {
                    Log::error('sendRawMarkdownMessage plain retry failed: '.$e2->getMessage());
                }
            }
        }
    }

    protected function startRenewalPurchaseProcess($user, $originalOrderId, $messageId)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);

        if (!$originalOrder || !$originalOrder->plan || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر برای تمدید یافت نشد یا معتبر نیست.", $messageId);
            return;
        }

        $plan = $originalOrder->plan;
        $balance = $user->balance ?? 0;
        $expiresAt = Carbon::parse($originalOrder->expires_at);

        $message = "🔄 *تایید تمدید سرویس*\n\n";
        $message .= "▫️ سرویس: *{$this->escape($plan->name)}*\n";
        $message .= "▫️ تاریخ انقضای فعلی: *" . $this->escape($expiresAt->format('Y/m/d')) . "*\n";
        $message .= "▫️ هزینه تمدید ({$plan->duration_days} روز): *" . number_format($plan->price) . " تومان*\n";
        $message .= "▫️ موجودی کیف پول: *" . number_format($balance) . " تومان*\n\n";
        $message .= "لطفاً روش پرداخت برای تمدید را انتخاب کنید:";

        $keyboard = Keyboard::make()->inline();
        if ($balance >= $plan->price) {
            $keyboard->row([Keyboard::inlineButton(['text' => '✅ تمدید با کیف پول (آنی)', 'callback_data' => "renew_pay_wallet_{$originalOrderId}"])]);
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '💳 تمدید با کارت به کارت', 'callback_data' => "renew_pay_card_{$originalOrderId}"])]);
        if ($this->isPlisioActive()) {
            $keyboard->row([Keyboard::inlineButton(['text' => '💎 تمدید با Plisio', 'callback_data' => "renew_pay_plisio_{$originalOrderId}"])]);
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به سرویس‌ها', 'callback_data' => '/my_services'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function processRenewalWalletPayment($user, $originalOrderId, $messageId)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);

        // بررسی‌های اولیه
        if (!$originalOrder || !$originalOrder->plan || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر برای تمدید یافت نشد.", $messageId);
            return;
        }

        $plan = $originalOrder->plan;

        // بررسی موجودی قبل از هر کاری
        if ($user->balance < $plan->price) {
            $keyboard = Keyboard::make()->inline()
                ->row([
                    Keyboard::inlineButton(['text' => '💳 شارژ کیف پول', 'callback_data' => '/deposit']),
                    Keyboard::inlineButton(['text' => '⬅️ بازگشت', 'callback_data' => '/my_services'])
                ]);
            $this->sendOrEditMessage($user->telegram_chat_id, "❌ موجودی کیف پول شما برای تمدید کافی نیست.", $keyboard, $messageId);
            return;
        }

        $newRenewalOrder = null;
        $provisionData = null;

        try {
            DB::transaction(function () use ($user, $originalOrder, $plan, &$newRenewalOrder, &$provisionData) {

                $user->decrement('balance', $plan->price);


                $newRenewalOrder = $user->orders()->create([
                    'plan_id' => $plan->id,
                    'status' => 'paid',
                    'source' => 'telegram_renewal',
                    'amount' => $plan->price,
                    'expires_at' => null,
                    'payment_method' => 'wallet',
                    'panel_username' => $originalOrder->panel_username,
                ]);

                $newRenewalOrder->renews_order_id = $originalOrder->id;
                $newRenewalOrder->save();


                Transaction::create([
                    'user_id' => $user->id,
                    'order_id' => $newRenewalOrder->id,
                    'amount' => -$plan->price,
                    'type' => 'purchase',
                    'status' => 'completed',
                    'description' => "تمدید سرویس {$plan->name} (سفارش اصلی #{$originalOrder->id})"
                ]);


                $provisionData = $this->renewUserAccount($originalOrder, $plan);

                if (!$provisionData) {
                    throw new \Exception('تمدید در پنل با خطا مواجه شد.');
                }


            });


            $newExpiryDate = Carbon::parse($originalOrder->refresh()->expires_at);
            $daysText = $this->escape($plan->duration_days . ' روز');
            $dateText = $this->escape($newExpiryDate->format('Y/m/d'));
            $planName = $this->escape($plan->name);


            $linkCode = $provisionData['link'];

            $successMessage = "⚡️ *سرویس شما با قدرت تمدید شد!* ⚡️\n\n";
            $successMessage .= "💎 *پلن:* {$planName}\n";
            $successMessage .= "⏳ *مدت افزوده شده:* {$daysText}\n";
            $successMessage .= "📅 *انقضای جدید:* {$dateText}\n\n";
            $successMessage .= "🔗 *لینک اتصال شما (بدون تغییر):*\n";
            $successMessage .= "👇 _برای کپی روی لینک زیر ضربه بزنید_\n";
            $successMessage .= "{$linkCode}";
            $keyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services']),
                Keyboard::inlineButton(['text' => '🏠 منوی اصلی', 'callback_data' => '/start'])
            ]);

            $this->sendOrEditMessage($user->telegram_chat_id, $successMessage, $keyboard, $messageId);

        } catch (\Exception $e) {
            Log::error('Renewal Wallet Payment Failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'original_order_id' => $originalOrderId,
                'user_id' => $user->id
            ]);


            if ($newRenewalOrder) {
                try {
                    $user->increment('balance', $plan->price);
                } catch (\Exception $refundEx) {
                    Log::critical("Failed to refund user {$user->id}: " . $refundEx->getMessage());
                }
                $newRenewalOrder->delete();
            }

            $errorKeyboard = Keyboard::make()->inline()->row([
                Keyboard::inlineButton(['text' => '💬 پشتیبانی', 'callback_data' => '/support_menu'])
            ]);

            $errorMessage = $this->escape("⚠️ تمدید با خطا مواجه شد. مبلغ {$plan->price} تومان به کیف پول شما بازگردانده شد.");
            $this->sendOrEditMessage($user->telegram_chat_id, $errorMessage, $errorKeyboard, $messageId);
        }
    }
    protected function handleRenewCardPayment($user, $originalOrderId, $messageId)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);
        if (!$originalOrder || !$originalOrder->plan || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, "❌ سرویس مورد نظر برای تمدید یافت نشد.", $messageId);
            return;
        }
        $plan = $originalOrder->plan;

        // ساخت سفارش بدون renews_order_id در create
        $newRenewalOrder = $user->orders()->create([
            'plan_id' => $plan->id,
            'status' => 'pending',
            'source' => 'telegram_renewal',
            'amount' => $plan->price,
            'expires_at' => null,
            'panel_username' => $originalOrder->panel_username,

        ]);


        $newRenewalOrder->renews_order_id = $originalOrder->id;
        $newRenewalOrder->save();

        $this->sendCardPaymentInfo($user->telegram_chat_id, $newRenewalOrder->id, $messageId);
    }

    protected function handleRenewPlisioPayment($user, $originalOrderId, $messageId)
    {
        $originalOrder = $user->orders()->with('plan')->find($originalOrderId);
        if (! $originalOrder || ! $originalOrder->plan || $originalOrder->status !== 'paid') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, '❌ سرویس مورد نظر برای تمدید یافت نشد.', $messageId);

            return;
        }
        $plan = $originalOrder->plan;

        $newRenewalOrder = $user->orders()->create([
            'plan_id' => $plan->id,
            'status' => 'pending',
            'source' => 'telegram_renewal',
            'amount' => $plan->price,
            'expires_at' => null,
            'panel_username' => $originalOrder->panel_username,
        ]);
        $newRenewalOrder->renews_order_id = $originalOrder->id;
        $newRenewalOrder->save();

        $this->startPlisioPayment($user, $newRenewalOrder->id, $messageId);
    }

    protected function isPlisioActive(): bool
    {
        return (new PlisioService($this->settings))->isEnabled();
    }

    protected function startPlisioPayment(User $user, int $orderId, ?int $messageId): void
    {
        $order = Order::find($orderId);
        if (! $order || $order->user_id !== $user->id || $order->status !== 'pending') {
            $this->sendOrEditMainMenu($user->telegram_chat_id, '❌ سفارش نامعتبر یا دیگر در انتظار پرداخت نیست.', $messageId);

            return;
        }

        $plisio = new PlisioService($this->settings);
        if (! $plisio->isEnabled()) {
            $this->sendOrEditMainMenu($user->telegram_chat_id, '❌ درگاه Plisio در تنظیمات سایت فعال نیست.', $messageId);

            return;
        }

        try {
            $data = $plisio->createInvoice($order, $user->email ?? null);
            $order->update([
                'plisio_txn_id' => $data['txn_id'],
                'payment_method' => 'plisio',
            ]);
        } catch (\Throwable $e) {
            Log::error('Telegram Plisio invoice: '.$e->getMessage());
            $this->sendOrEditMainMenu($user->telegram_chat_id, '❌ خطا در ساخت فاکتور Plisio.', $messageId);

            return;
        }

        $msg = "💎 *پرداخت Plisio*\n\n▫️ مبلغ: *".number_format($order->amount)."* تومان\n\nبرای ادامه دکمه زیر را بزنید.";
        $keyboard = Keyboard::make()->inline()
            ->row([Keyboard::inlineButton(['text' => '🔗 صفحه پرداخت Plisio', 'url' => $data['invoice_url']])])
            ->row([Keyboard::inlineButton(['text' => '⬅️ منوی اصلی', 'callback_data' => '/start'])]);

        $this->sendOrEditMessage($user->telegram_chat_id, $msg, $keyboard, $messageId);
    }

    protected function renewUserAccount(Order $originalOrder, Plan $plan)
    {
        $settings = $this->settings;
        $user = $originalOrder->user;


        $uniqueUsername = $originalOrder->panel_username ?? "user-{$user->id}-order-{$originalOrder->id}";
        // محاسبه تاریخ انقضای جدید
        $currentExpiresAt = Carbon::parse($originalOrder->expires_at);
        $baseDate = $currentExpiresAt->isPast() ? now() : $currentExpiresAt;
        $newExpiryDate = $baseDate->copy()->addDays($plan->duration_days);




        $newDataLimitBytes = $plan->volume_gb * 1073741824; // تبدیل گیگابایت به بایت

        try {
            // ==========================================
            // پنل MARZBAN
            // ==========================================
            if (($settings->get('panel_type') ?? 'marzban') === 'marzban') {
                $marzban = new MarzbanService(
                    $settings->get('marzban_host'),
                    $settings->get('marzban_sudo_username'),
                    $settings->get('marzban_sudo_password'),
                    $settings->get('marzban_node_hostname')
                );

                $updateResponse = $marzban->updateUser($uniqueUsername, [
                    'expire' => $newExpiryDate->timestamp,
                    'data_limit' => $plan->volume_gb * 1073741824,
                ]);

                $resetResponse = $marzban->resetUserTraffic($uniqueUsername);

                if ($updateResponse !== null && $resetResponse !== null) {
                    Log::info("✅ Marzban: تمدید موفق", ['username' => $uniqueUsername]);

                    $originalOrder->update([
                        'expires_at' => $newExpiryDate
                    ]);

                    return [
                        'link' => $originalOrder->config_details,
                        'username' => $uniqueUsername
                    ];
                } else {
                    Log::error('❌ Marzban: تمدید ناموفق', [
                        'username' => $uniqueUsername,
                        'update' => $updateResponse,
                        'reset' => $resetResponse
                    ]);
                    return null;
                }
            }

            // ==========================================
            // پنل X-UI (SANAEI)
            // ==========================================
            elseif ($settings->get('panel_type') === 'xui') {
                $inboundId = $settings->get('xui_default_inbound_id');
                if (!$inboundId) {
                    throw new \Exception("❌ Inbound ID در تنظیمات یافت نشد.");
                }



                $xui = new XUIService(
                    $settings->get('xui_host'),
                    $settings->get('xui_user'),
                    $settings->get('xui_pass')
                );

                if (!$xui->login()) {
                    throw new \Exception('❌ خطا در لاگین به پنل X-UI.');
                }

                $inbound = Inbound::whereJsonContains('inbound_data->id', (int)$inboundId)->first();
                if (!$inbound || !$inbound->inbound_data) {
                    throw new \Exception("❌ اینباند با ID {$inboundId} در دیتابیس یافت نشد.");
                }

                $inboundData = is_string($inbound->inbound_data)
                    ? json_decode($inbound->inbound_data, true)
                    : $inbound->inbound_data;


                $linkType = $settings->get('xui_link_type', 'single');

                // پیدا کردن کلاینت توسط ایمیل
                $clients = $xui->getClients($inboundData['id']);

                if (empty($clients)) {
                    throw new \Exception('❌ هیچ کلاینتی در اینباند یافت نشد.');
                }

                $client = collect($clients)->firstWhere('email', $uniqueUsername);

                if (!$client) {
                    throw new \Exception("❌ کلاینت با ایمیل {$uniqueUsername} یافت نشد. امکان تمدید وجود ندارد.");
                }


                $clientData = [
                    'id' => $client['id'],
                    'email' => $uniqueUsername,
                    'total' => $plan->volume_gb * 1073741824,
                   'expiryTime' => $newExpiryDate->timestamp * 1000,


                ];

                if ($linkType === 'subscription' && isset($client['subId'])) {
                    $clientData['subId'] = $client['subId'];
                }



                $response = $xui->updateClient($inboundData['id'], $client['id'], $clientData);
                if ($response && isset($response['success']) && $response['success']) {

                    $xui->resetClientTraffic($inboundData['id'], $uniqueUsername);
                    $originalOrder->update([
                        'expires_at' => $newExpiryDate
                    ]);

                    return [
                        'link' => $originalOrder->config_details,
                        'username' => $uniqueUsername
                    ];
                } else {
                    $errorMsg = $response['msg'] ?? 'خطای نامشخص';
                    throw new \Exception("❌ خطا در بروزرسانی کلاینت: " . $errorMsg);
                }
            } else {
                throw new \Exception("❌ نوع پنل پشتیبانی نمی‌شود: " . $settings->get('panel_type'));
            }
        } catch (\Exception $e) {
            Log::error("❌ تمدید انجام نشد ({$uniqueUsername}): " . $e->getMessage());
            return null;
        }
    }

    protected function showSupportMenu($user, $messageId = null)
    {
        $tickets = $user->tickets()->latest()->take(4)->get();
        $message = "💬 *پشتیبانی*\n\n";
        if ($tickets->isEmpty()) {
            $message .= "شما تاکنون هیچ تیکتی ثبت نکرده‌اید.";
        } else {
            $message .= "لیست آخرین تیکت‌های شما:\n";
            foreach ($tickets as $ticket) {
                $status = match ($ticket->status) {
                    'open' => '🔵 باز',
                    'answered' => '🟢 پاسخ ادمین',
                    'closed' => '⚪️ بسته',
                    default => '⚪️ نامشخص',
                };
                $ticketIdEscaped = $this->escape((string)$ticket->id);
                $message .= "\n📌 *تیکت \\#{$ticketIdEscaped}* | " . $this->escape($status) . "\n";
                $message .= "*موضوع:* " . $this->escape($ticket->subject) . "\n";
                $message .= "_{$this->escape($ticket->updated_at->diffForHumans())}_";
            }
        }

        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '📝 ایجاد تیکت جدید', 'callback_data' => '/support_new'])]);
        foreach ($tickets as $ticket) {
            if ($ticket->status !== 'closed') {
                $keyboard->row([
                    Keyboard::inlineButton(['text' => "✏️ پاسخ/مشاهده تیکت #{$ticket->id}", 'callback_data' => "reply_ticket_{$ticket->id}"]),
                    Keyboard::inlineButton(['text' => "❌ بستن تیکت #{$ticket->id}", 'callback_data' => "close_ticket_{$ticket->id}"]),
                ]);
            }
        }
        $keyboard->row([Keyboard::inlineButton(['text' => '⬅️ بازگشت به منوی اصلی', 'callback_data' => '/start'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, $message, $keyboard, $messageId);
    }

    protected function promptForNewTicket($user, $messageId)
    {
        $user->update(['bot_state' => 'awaiting_new_ticket_subject']);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, "📝 لطفاً *موضوع* تیکت جدید را در یک پیام ارسال کنید:", $keyboard, $messageId);
    }

    protected function promptForTicketReply($user, $ticketId, $messageId)
    {
        $ticketIdEscaped = $this->escape($ticketId);
        $user->update(['bot_state' => 'awaiting_ticket_reply|' . $ticketId]);
        $keyboard = Keyboard::make()->inline()->row([Keyboard::inlineButton(['text' => '❌ انصراف', 'callback_data' => '/cancel_action'])]);
        $this->sendOrEditMessage($user->telegram_chat_id, "✏️ لطفاً پاسخ خود را برای تیکت \\#{$ticketIdEscaped} وارد کنید (می‌توانید عکس هم ارسال کنید):", $keyboard, $messageId);
    }

    protected function closeTicket($user, $ticketId, $messageId, $callbackQueryId)
    {
        $ticket = $user->tickets()->where('id', $ticketId)->first();
        if ($ticket && $ticket->status !== 'closed') {
            $ticket->update(['status' => 'closed']);
            try {
                Telegram::answerCallbackQuery([
                    'callback_query_id' => $callbackQueryId,
                    'text' => "تیکت #{$ticketId} بسته شد.",
                    'show_alert' => false
                ]);
            } catch (\Exception $e) { Log::warning("Could not answer close ticket query: ".$e->getMessage());}
            $this->showSupportMenu($user, $messageId);
        } else {
            try { Telegram::answerCallbackQuery(['callback_query_id' => $callbackQueryId, 'text' => "تیکت یافت نشد یا قبلا بسته شده.", 'show_alert' => true]); } catch (\Exception $e) {}
        }
    }

    protected function processTicketConversation($user, $text, $update)
    {
        $state = $user->bot_state;
        $chatId = $user->telegram_chat_id;

        try {
            if ($state === 'awaiting_new_ticket_subject') {
                if (mb_strlen($text) < 3) {
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ موضوع باید حداقل ۳ حرف باشد. لطفا دوباره تلاش کنید."), 'parse_mode' => 'MarkdownV2']);
                    return;
                }
                $user->update(['bot_state' => 'awaiting_new_ticket_message|' . $text]);
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("✅ موضوع دریافت شد.\n\nحالا *متن پیام* خود را وارد کنید (می‌توانید همراه پیام، عکس هم ارسال کنید):"), 'parse_mode' => 'MarkdownV2']);

            } elseif (Str::startsWith($state, 'awaiting_new_ticket_message|')) {
                $subject = Str::after($state, 'awaiting_new_ticket_message|');
                $isPhotoOnly = $update->getMessage()->has('photo') && (empty(trim($text)) || $text === '[📎 فایل پیوست شد]');
                $messageText = $isPhotoOnly ? '[📎 پیوست تصویر]' : $text;

                if (empty(trim($messageText))) {
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ متن پیام نمی‌تواند خالی باشد. لطفا پیام خود را وارد کنید:"), 'parse_mode' => 'MarkdownV2']);
                    return;
                }

                $ticket = $user->tickets()->create([
                    'subject' => $subject,
                    'message' => $messageText,
                    'priority' => 'medium', 'status' => 'open', 'source' => 'telegram', 'user_id' => $user->id
                ]);

                $replyData = ['user_id' => $user->id, 'message' => $messageText];
                if ($update->getMessage()->has('photo')) {
                    try { $replyData['attachment_path'] = $this->savePhotoAttachment($update, 'ticket_attachments'); }
                    catch (\Exception $e) { Log::error("Error saving photo for new ticket {$ticket->id}: " . $e->getMessage()); }
                }
                $reply = $ticket->replies()->create($replyData);

                $user->update(['bot_state' => null]);
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("✅ تیکت #{$ticket->id} با موفقیت ثبت شد."), 'parse_mode' => 'MarkdownV2']);
                $this->sendOrEditMainMenu($chatId, "پشتیبانی به زودی پاسخ شما را خواهد داد.");

                event(new TicketCreated($ticket));

            } elseif (Str::startsWith($state, 'awaiting_ticket_reply|')) {
                $ticketId = Str::after($state, 'awaiting_ticket_reply|');
                $ticket = $user->tickets()->find($ticketId);

                if (!$ticket) {
                    $this->sendOrEditMainMenu($chatId, "❌ تیکت مورد نظر یافت نشد.");
                    return;
                }

                $isPhotoOnly = $update->getMessage()->has('photo') && (empty(trim($text)) || $text === '[📎 فایل پیوست شد]');
                $messageText = $isPhotoOnly ? '[📎 پیوست تصویر]' : $text;

                if (empty(trim($messageText))) {
                    Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("❌ متن پاسخ نمی‌تواند خالی باشد."), 'parse_mode' => 'MarkdownV2']);
                    return;
                }

                $replyData = ['user_id' => $user->id, 'message' => $messageText];
                if ($update->getMessage()->has('photo')) {
                    try { $replyData['attachment_path'] = $this->savePhotoAttachment($update, 'ticket_attachments'); }
                    catch (\Exception $e) { Log::error("Error saving photo for ticket reply {$ticketId}: " . $e->getMessage()); }
                }
                $reply = $ticket->replies()->create($replyData);
                $ticket->update(['status' => 'open']);

                $user->update(['bot_state' => null]);
                Telegram::sendMessage(['chat_id' => $chatId, 'text' => $this->escape("✅ پاسخ شما برای تیکت #{$ticketId} ثبت شد."), 'parse_mode' => 'MarkdownV2']);
                $this->sendOrEditMainMenu($chatId, "پشتیبانی به زودی پاسخ شما را خواهد داد.");

                event(new TicketReplied($reply));
            }
        } catch (\Exception $e) {
            Log::error('Failed to process ticket conversation: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            $user->update(['bot_state' => null]);
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => $this->escape("❌ خطایی در پردازش پیام شما رخ داد. لطفاً دوباره تلاش کنید."),
                'parse_mode' => 'MarkdownV2'
            ]);
        }
    }

    protected function isUserMemberOfChannel($user)
    {
        $forceJoin = $this->settings->get('force_join_enabled', '0');

        Log::info("Force Join Check", [
            'enabled_value' => $forceJoin,
            'type' => gettype($forceJoin)
        ]);

        if (!in_array($forceJoin, ['1', 1, true, 'on'], true)) {
            Log::info("Force join is disabled, skipping membership check.");
            return true;
        }

        // دریافت لیست کانال‌های اجباری
        $requiredChannels = $this->getRequiredChannels();
        
        if (empty($requiredChannels)) {
            Log::error('❌ FORCE JOIN IS ENABLED BUT NO CHANNELS ARE SET!');
            return false;
        }

        $botToken = TelegramBotToken::normalize($this->settings->get('telegram_bot_token'));
        if (empty($botToken)) {
            Log::error('❌ Bot token is not set!');
            return false;
        }

        // بررسی عضویت در همه کانال‌ها
        foreach ($requiredChannels as $channel) {
            $channelId = $channel['channel_id'] ?? null;
            if (empty($channelId)) {
                continue;
            }

            try {
                Log::info("🔍 Checking membership...", [
                    'channel_id' => $channelId,
                    'user_chat_id' => $user->telegram_chat_id
                ]);

                $apiUrl = "https://api.telegram.org/bot{$botToken}/getChatMember";

                $response = \Illuminate\Support\Facades\Http::timeout(10)->get($apiUrl, [
                    'chat_id' => $channelId,
                    'user_id' => $user->telegram_chat_id,
                ]);

                if (!$response->successful()) {
                    Log::error("❌ Telegram API request failed", [
                        'status' => $response->status(),
                        'body' => $response->body(),
                        'channel_id' => $channelId,
                        'user_id' => $user->telegram_chat_id
                    ]);
                    return false;
                }

                $data = $response->json();
                $status = $data['result']['status'] ?? 'left';

                Log::info("✅ Membership check result", [
                    'user_id' => $user->telegram_chat_id,
                    'channel_id' => $channelId,
                    'status' => $status
                ]);

                // اگر در این کانال عضو نباشد، false برگردان
                if (!in_array($status, ['member', 'administrator', 'creator'], true)) {
                    Log::info("❌ User is not a member of channel", [
                        'channel_id' => $channelId,
                        'status' => $status
                    ]);
                    return false;
                }

            } catch (\Exception $e) {
                Log::error("❌ Exception in membership check", [
                    'error' => $e->getMessage(),
                    'channel_id' => $channelId,
                    'user_id' => $user->telegram_chat_id
                ]);
                return false;
            }
        }

        // اگر در همه کانال‌ها عضو بود
        return true;
    }

    /**
     * دریافت لیست کانال‌های اجباری
     */
    protected function getRequiredChannels()
    {
        // ابتدا سعی کن از فرمت جدید استفاده کنی
        $channels = $this->settings->get('telegram_required_channels');
        
        // اگر به صورت JSON string است، decode کن
        if (is_string($channels)) {
            $decoded = json_decode($channels, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        
        // اگر آرایه است، برگردان
        if (is_array($channels) && !empty($channels)) {
            return $channels;
        }
        
        // اگر فرمت قدیمی وجود دارد، تبدیل کن
        $oldChannelId = $this->settings->get('telegram_required_channel_id');
        if (!empty($oldChannelId)) {
            return [
                [
                    'channel_id' => $oldChannelId,
                    'channel_name' => null
                ]
            ];
        }
        
        return [];
    }

    protected function showChannelRequiredMessage($chatId, $messageId = null)
    {
        $requiredChannels = $this->getRequiredChannels();

        if (empty($requiredChannels)) {
            $message = "❌ خطا: کانال عضویت اجباری تنظیم نشده است.";
            $this->sendOrEditMessage($chatId, $message, null, $messageId);
            return;
        }

        $message = "⛔️ *عضویت در کانال‌های زیر الزامی است!*\n\n";
        $message .= "برای ادامه استفاده از ربات، باید در تمام کانال‌های زیر عضو شوید:\n\n";
        
        $keyboard = Keyboard::make()->inline();
        $channelButtons = [];

        foreach ($requiredChannels as $index => $channel) {
            $channelId = $channel['channel_id'] ?? null;
            $channelName = $channel['channel_name'] ?? null;
            
            if (empty($channelId)) {
                continue;
            }

            $channelLink = null;
            $channelDisplayName = $channelName ?? $channelId;

            if (str_starts_with($channelId, '@')) {
                $username = ltrim($channelId, '@');
                $channelLink = "https://t.me/{$username}";
                $channelDisplayName = $channelName ?: "@" . $username;
            } elseif (preg_match('/^-100\d+$/', $channelId)) {
                $channelDisplayName = $channelName ?: "کانال خصوصی " . ($index + 1);
                $channelLink = $this->settings->get('telegram_private_channel_invite_link');
            } else {
                $channelDisplayName = $channelName ?: "کانال " . ($index + 1);
                Log::error("Invalid channel ID format", ['channel_id' => $channelId]);
            }

            $message .= "📢 {$channelDisplayName}\n";

            // اضافه کردن دکمه برای هر کانال
            if (!empty($channelLink)) {
                $channelButtons[] = Keyboard::inlineButton([
                    'text' => "📲 {$channelDisplayName}",
                    'url' => $channelLink
                ]);
            }
        }

        $message .= "\n🔹 پس از عضویت در تمام کانال‌ها، روی دکمه «✅ بررسی عضویت» بزنید.";

        // اضافه کردن دکمه‌های کانال‌ها (حداکثر 2 دکمه در هر ردیف)
        if (!empty($channelButtons)) {
            $chunkedButtons = array_chunk($channelButtons, 2);
            foreach ($chunkedButtons as $row) {
                $keyboard->row($row);
            }
        }

        $keyboard->row([Keyboard::inlineButton(['text' => '✅ بررسی عضویت', 'callback_data' => '/check_membership'])]);

        $this->sendOrEditMessage($chatId, $message, $keyboard, $messageId);
    }

    protected function savePhotoAttachment($update, $directory)
    {
        $photo = collect($update->getMessage()->getPhoto())->last();
        if(!$photo) return null;

        $botToken = TelegramBotToken::normalize($this->settings->get('telegram_bot_token'));
        try {
            $file = Telegram::getFile(['file_id' => $photo->getFileId()]);
            $filePath = method_exists($file, 'getFilePath') ? $file->getFilePath() : ($file['file_path'] ?? null);
            if(!$filePath) { throw new \Exception('File path not found in Telegram response.'); }

            if (empty($botToken)) {
                throw new \Exception('Telegram bot token is not set.');
            }
            $fileContents = file_get_contents("https://api.telegram.org/file/bot{$botToken}/{$filePath}");
            if ($fileContents === false) { throw new \Exception('Failed to download file content.');}

            Storage::disk('public')->makeDirectory($directory);
            $extension = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'jpg';
            $fileName = $directory . '/' . Str::random(40) . '.' . $extension;
            $success = Storage::disk('public')->put($fileName, $fileContents);

            if (!$success) { throw new \Exception('Failed to save file to storage.'); }

            return $fileName;

        } catch (\Exception $e) {
            Log::error('Error saving photo attachment: ' . $e->getMessage(), ['file_id' => $photo->getFileId()]);
            return null;
        }
    }


    protected function handleTrialRequest($user)
    {
        $settings = $this->settings;
        $chatId = $user->telegram_chat_id !== null && $user->telegram_chat_id !== ''
            ? (int) $user->telegram_chat_id
            : null;
        if ($chatId === null || $chatId === 0) {
            Log::error('Trial request: missing telegram_chat_id', ['user_id' => $user->id]);

            return;
        }

        Log::info('Trial request initiated', [
            'user_id' => $user->id,
            'trial_enabled_value' => $settings->get('trial_enabled'),
            'trial_enabled_type' => gettype($settings->get('trial_enabled')),
            'trial_accounts_taken' => $user->trial_accounts_taken ?? 0
        ]);

        $trialEnabled = filter_var($settings->get('trial_enabled') ?? '0', FILTER_VALIDATE_BOOLEAN);
        if (! $trialEnabled) {
            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ قابلیت دریافت اکانت تست در حال حاضر غیرفعال است.',
                ]);
            } catch (\Throwable $e) {
                Log::error('Trial disabled message failed: '.$e->getMessage(), ['chat_id' => $chatId]);
            }
            Log::warning('Trial account is disabled in settings');

            return;
        }

        $limit = (int) $settings->get('trial_limit_per_user', 1);
        $currentTrials = $user->trial_accounts_taken ?? 0;

        if ($currentTrials >= $limit) {
            $plain = "❗️ شما به حداکثر تعداد اکانت تست رسیده‌اید.\n\n"
                ."تعداد اکانت تست گرفته‌شده: {$currentTrials} از {$limit}\n\n"
                .'دیگر مجاز به دریافت اکانت تست نیستید.';
            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $plain,
                ]);
            } catch (\Throwable $e) {
                Log::error('Trial limit message failed: '.$e->getMessage(), ['chat_id' => $chatId]);
            }
            Log::info('User trial limit reached', ['current' => $currentTrials, 'limit' => $limit]);

            return;
        }

        try {
            $volumeMB = (int) $settings->get('trial_volume_mb', 500);
            $durationHours = (int) $settings->get('trial_duration_hours', 24);

            $uniqueUsername = "trial-{$user->id}-" . ($currentTrials + 1);
            $expiresAt = now()->addHours($durationHours);
            $dataLimitBytes = $volumeMB * 1024 * 1024;

            $panelType = $settings->get('panel_type');
            $configLink = null;

            if ($panelType === 'marzban') {
                $marzbanService = new MarzbanService($settings->get('marzban_host'), $settings->get('marzban_sudo_username'), $settings->get('marzban_sudo_password'), $settings->get('marzban_node_hostname'));
                $response = $marzbanService->createUser([
                    'username' => $uniqueUsername,
                    'expire' => $expiresAt->timestamp,
                    'data_limit' => $dataLimitBytes,
                ]);

                if ($response && !empty($response['subscription_url'])) {
                    $configLink = $response['subscription_url'];
                } else {
                    throw new \Exception('خطا در ارتباط با پنل مرزبان.');
                }

            } elseif ($panelType === 'xui') {
                $xuiService = new XUIService($settings->get('xui_host'), $settings->get('xui_user'), $settings->get('xui_pass'));
                $inboundId = $settings->get('xui_default_inbound_id');
                
                // تبدیل به integer برای جستجو
                $inboundIdInt = !empty($inboundId) ? (int) $inboundId : null;
                $inbound = null;
                
                if ($inboundIdInt) {
                    $inbound = Inbound::whereJsonContains('inbound_data->id', $inboundIdInt)
                        ->orWhere('inbound_data->id', $inboundIdInt)
                        ->first();
                }

                // اگر اینباند پیش‌فرض یافت نشد، از اولین اینباند فعال استفاده کن
                if (!$inbound || !$inbound->inbound_data) {
                    $inbound = Inbound::whereJsonContains('inbound_data->enable', true)
                        ->orWhere('inbound_data->enable', '1')
                        ->first();
                    
                    if ($inbound && $inbound->inbound_data) {
                        $foundId = $inbound->inbound_data['id'] ?? 'نامشخص';
                        Log::warning('Default inbound not found, using first active inbound', [
                            'requested_id' => $inboundId,
                            'using_id' => $foundId
                        ]);
                    } else {
                        // اگر هیچ اینباند فعالی یافت نشد، خطا بده
                        $availableInbounds = Inbound::all()->map(function($inbound) {
                            return $inbound->inbound_data['id'] ?? null;
                        })->filter()->toArray();
                        $availableIds = implode(', ', $availableInbounds);
                        
                        throw new \Exception(
                            ($inboundId ? "اینباند با ID {$inboundId} در دیتابیس یافت نشد. " : "اینباند پیش‌فرض در تنظیمات مشخص نشده است. ") .
                            "لطفاً ابتدا اینباندها را sync کنید: پنل مدیریت → اینباندها → Sync از X-UI. " .
                            ($availableIds ? "اینباندهای موجود در دیتابیس: {$availableIds}" : "هیچ اینباندی در دیتابیس sync نشده است.") .
                            " سپس در تنظیمات → تنظیمات پنل V2Ray → اینباند پیش‌فرض را انتخاب کنید."
                        );
                    }
                }

                if (!$xuiService->login()) {
                    throw new \Exception('خطا در لاگین به پنل X-UI.');
                }

                $inboundData = $inbound->inbound_data;

                $clientData = [
                    'email' => $uniqueUsername,
                    'total' => $dataLimitBytes,
                    'expiryTime' => $expiresAt->timestamp * 1000,
                ];

                $response = $xuiService->addClient($inboundData['id'], $clientData);

                if ($response && isset($response['success']) && $response['success']) {
                    $linkType = $settings->get('xui_link_type', 'single');

                    if ($linkType === 'subscription') {
                        $subId = $response['generated_subId'] ?? $uniqueUsername;
                        $subBaseUrl = rtrim($settings->get('xui_subscription_url_base'), '/');

                        if ($subBaseUrl && $subId !== $uniqueUsername) {
                            $configLink = $subBaseUrl . '/sub/' . $subId;
                            Log::info('XUI: Subscription link generated for trial', ['link' => $configLink]);
                        } else {
                            Log::error("XUI Subscription: base URL or subId missing for trial.", [
                                'base_url' => $subBaseUrl,
                                'subId' => $subId,
                                'response' => $response
                            ]);
                            throw new \Exception('تنظیمات لینک سابسکرپشن ناقص است.');
                        }
                    } else {
                        $uuid = $response['generated_uuid'] ?? null;
                        if(!$uuid) {
                            $clientSettings = json_decode($response['obj']['settings'] ?? '{}', true);
                            $uuid = $clientSettings['clients'][0]['id'] ?? null;
                        }
                        if (!$uuid) throw new \Exception('UUID از پاسخ X-UI استخراج نشد.');

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
                            $serverAddress = $settings->get('server_address_for_link', parse_url($settings->get('xui_host'), PHP_URL_HOST));
                        }
                        
                        // استفاده از remark از inboundData و ترکیب با uniqueUsername
                        // uniqueUsername خودش شامل "trial-" است پس فقط با خط تیره جدا می‌کنیم
                        $inboundRemark = $inboundData['remark'] ?? 'Trial';
                        $remark = $inboundRemark . '-' . $uniqueUsername;
                        
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
                        
                        // افزودن DNS Resolver (DoH/DoT) برای trial
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
                        $configLink = "vless://{$uuid}@{$serverAddress}:{$port}?{$params}#" . urlencode($remark);
                        Log::info('XUI: Single link generated for trial', ['link' => $configLink]);
                    }

                } else {
                    throw new \Exception($response['msg'] ?? 'خطا در ساخت کاربر در پنل X-UI');
                }
            } else {
                throw new \Exception('نوع پنل در تنظیمات مشخص نشده است.');
            }

            if ($configLink) {
                $user->increment('trial_accounts_taken');
                
                // محاسبه شماره اکانت تست فعلی و تعداد باقی‌مانده
                $trialNumber = $currentTrials + 1;
                $remainingTrials = max(0, $limit - $trialNumber);

                $h = static fn ($t) => htmlspecialchars((string) $t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $message = '<b>✨ اکانت تست شما آماده است!</b>'."\n\n";
                $message .= "━━━━━━━━━━━━━━━━\n\n";
                $message .= '🔢 شماره اکانت تست: <code>'.$h($trialNumber).'</code> از <code>'.$h($limit)."</code>\n";

                if ($remainingTrials > 0) {
                    $message .= '📊 باقی‌مانده: <code>'.$h($remainingTrials).' اکانت تست</code>'."\n";
                } else {
                    $message .= "⚠️ <b>این آخرین اکانت تست شماست!</b>\n";
                }

                $message .= "\n━━━━━━━━━━━━━━━━\n\n";
                $message .= '📦 حجم: <code>'.$h($volumeMB).' مگابایت</code>'."\n";
                $message .= '⏳ اعتبار: <code>'.$h($durationHours).' ساعت</code>'."\n\n";
                $message .= "━━━━━━━━━━━━━━━━\n\n";
                $message .= "🔗 لینک اتصال در پیام بعدی ارسال می‌شود.\n";
                $message .= '💡 نکته: لینک را انتخاب کرده و کپی کنید.';

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'HTML',
                ]);

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '<code>'.$h($configLink).'</code>',
                    'parse_mode' => 'HTML',
                ]);

                Log::info('Trial account created successfully', [
                    'user_id' => $user->id,
                    'username' => $uniqueUsername
                ]);
            } else {
                throw new \Exception('لینک کانفیگ پس از ساخت کاربر دریافت نشد.');
            }

        } catch (\Exception $e) {
            Log::error('Trial Account Creation Failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ خطا در ساخت اکانت تست. لطفاً بعداً تلاش کنید.',
                ]);
            } catch (\Throwable $sendEx) {
                Log::error('Trial error user notify failed: '.$sendEx->getMessage(), ['chat_id' => $chatId]);
            }
        }
    }

    protected function sendOrEditMessage($chatId, $text, $keyboard, $messageId = null)
    {
        // بدون parse_mode: MarkdownV2 + escape() روی متن فارسی/پویا اغلب باعث خطای API (و در SDK گاهی «Not Found») می‌شود.
        $payload = [
            'chat_id'      => (int) $chatId,
            'text'         => $text,
            'reply_markup' => $keyboard,
        ];
        try {
            if ($messageId) {
                $payload['message_id'] = $messageId;
                Telegram::editMessageText($payload);
            } else {
                Telegram::sendMessage($payload);
            }
        } catch (\Telegram\Bot\Exceptions\TelegramResponseException $e) {
            if (Str::contains($e->getMessage(), ['message is not modified'])) {
                Log::info("Message not modified.", ['chat_id' => $chatId]);
            } elseif (Str::contains($e->getMessage(), ['message to edit not found', 'message identifier is not specified'])) {
                Log::warning("Could not edit message {$messageId}. Sending new.", ['error' => $e->getMessage()]);
                unset($payload['message_id']);
                try { Telegram::sendMessage($payload); } catch (\Exception $e2) {Log::error("Failed to send new message after edit failure: " . $e2->getMessage());}
            } else {
                Log::error("Telegram API error: " . $e->getMessage(), ['payload' => $payload, 'trace' => $e->getTraceAsString()]);
                if ($messageId) {
                    unset($payload['message_id']);
                    try { Telegram::sendMessage($payload); } catch (\Exception $e2) {Log::error("Failed to send new message after API error: " . $e2->getMessage());}
                }
            }
        }
        catch (\Exception $e) {
            Log::error("General error during send/edit message: " . $e->getMessage(), ['chat_id' => $chatId, 'trace' => $e->getTraceAsString()]);
            if($messageId) {
                unset($payload['message_id']);
                try { Telegram::sendMessage($payload); } catch (\Exception $e2) {Log::error("Failed to send new message after general failure: " . $e2->getMessage());}
            }
        }
    }

    protected function escape(string $text): string
    {
        $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $text = str_replace('\\', '\\\\', $text);
        return str_replace($chars, array_map(fn($char) => '\\' . $char, $chars), $text);
    }

    protected function getMainMenuKeyboard(): Keyboard
    {
        return Keyboard::make()->inline()
            ->row([
                Keyboard::inlineButton(['text' => '🛒 خرید سرویس', 'callback_data' => '/plans']),
                Keyboard::inlineButton(['text' => '🧪 اکانت تست', 'callback_data' => '🧪 اکانت تست']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '💰 کیف پول', 'callback_data' => '/wallet']),
                Keyboard::inlineButton(['text' => '🎁 دعوت از دوستان', 'callback_data' => '/referral']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '💬 پشتیبانی', 'callback_data' => '/support_menu']),
                Keyboard::inlineButton(['text' => '📚 راهنمای اتصال', 'callback_data' => '/tutorials']),
            ])
            ->row([
                Keyboard::inlineButton(['text' => '🛠 سرویس‌های من', 'callback_data' => '/my_services']),
            ]);
    }

    protected function sendOrEditMainMenu($chatId, $text, $messageId = null)
    {
        $this->sendOrEditMessage($chatId, $text, $this->getMainMenuKeyboard(), $messageId);
    }

    protected function getReplyMainMenu(): Keyboard
    {
        return Keyboard::make([
            'keyboard' => [
                ['🛒 خرید سرویس', '🧪 اکانت تست'],
                ['💰 کیف پول', '📜 تاریخچه تراکنش‌ها'],
                ['💬 پشتیبانی', '🎁 دعوت از دوستان'],
                ['📚 راهنمای اتصال', '🛠 سرویس‌های من'],

            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ]);
    }
}
