<?php

namespace Database\Seeders;

use App\Models\BotMessage;
use Illuminate\Database\Seeder;

class BotMessageSeeder extends Seeder
{
    public function run(): void
    {
        $messages = [
            // دکمه‌ها - Buttons
            [
                'key' => 'btn_payment_card',
                'category' => 'buttons',
                'title' => 'دکمه: پرداخت کارت به کارت',
                'content' => '💳 کارت به کارت',
                'description' => 'متن دکمه پرداخت کارت به کارت در صفحه انتخاب روش پرداخت',
            ],
            [
                'key' => 'btn_payment_wallet',
                'category' => 'buttons',
                'title' => 'دکمه: پرداخت با کیف پول',
                'content' => '✅ پرداخت با کیف پول',
                'description' => 'متن دکمه پرداخت با موجودی کیف پول',
            ],
            [
                'key' => 'btn_manual_crypto',
                'category' => 'buttons',
                'title' => 'دکمه: پرداخت کریپتو دستی',
                'content' => '💠 USDT / USDC (دستی)',
                'description' => 'متن دکمه پرداخت با کریپتو به صورت دستی',
            ],
            [
                'key' => 'btn_payment_online',
                'category' => 'buttons',
                'title' => 'دکمه: پرداخت آنلاین',
                'content' => '🌐 پرداخت آنلاین ارزی / کریپتو',
                'description' => 'متن دکمه پرداخت آنلاین برای درگاه‌های ارزی و کریپتو',
            ],
            [
                'key' => 'btn_payment_plisio',
                'category' => 'buttons',
                'title' => 'دکمه: پرداخت Plisio',
                'content' => '💎 پرداخت Plisio (کریپتو)',
                'description' => 'متن دکمه پرداخت با درگاه Plisio',
            ],
            [
                'key' => 'btn_discount_enter',
                'category' => 'buttons',
                'title' => 'دکمه: ثبت کد تخفیف',
                'content' => '🎫 ثبت کد تخفیف',
                'description' => 'متن دکمه ثبت کد تخفیف',
            ],
            [
                'key' => 'btn_discount_remove',
                'category' => 'buttons',
                'title' => 'دکمه: حذف کد تخفیف',
                'content' => '❌ حذف کد تخفیف',
                'description' => 'متن دکمه حذف کد تخفیف اعمال شده',
            ],
            [
                'key' => 'btn_back_to_plans',
                'category' => 'buttons',
                'title' => 'دکمه: بازگشت به پلن‌ها',
                'content' => '⬅️ بازگشت به پلن‌ها',
                'description' => 'متن دکمه بازگشت به لیست پلن‌ها',
            ],
            [
                'key' => 'btn_back_to_gateways',
                'category' => 'buttons',
                'title' => 'دکمه: بازگشت به درگاه‌ها',
                'content' => '⬅️ بازگشت به لیست درگاه‌ها',
                'description' => 'متن دکمه بازگشت به لیست درگاه‌های پرداخت',
            ],
            [
                'key' => 'btn_payment_check',
                'category' => 'buttons',
                'title' => 'دکمه: بررسی پرداخت',
                'content' => '✅ پرداخت کردم، بررسی کن',
                'description' => 'متن دکمه بررسی پرداخت بعد از پرداخت آنلاین',
            ],

            // پیام‌ها - Messages
            [
                'key' => 'msg_invoice_header',
                'category' => 'messages',
                'title' => 'پیام: سربرگ فاکتور',
                'content' => "🛒 *تایید خرید*\n\n▫️ پلن: *{plan_name}*\n▫️ قیمت: *{amount} تومان*\n▫️ موجودی (XMPlus): *{balance}*\n\nلطفاً روش پرداخت را انتخاب کنید:",
                'description' => 'پیام نمایش فاکتور خرید. متغیرها: {plan_name}, {amount}, {balance}',
            ],
            [
                'key' => 'msg_online_gateway_picker',
                'category' => 'messages',
                'title' => 'پیام: انتخاب درگاه آنلاین',
                'content' => "💳 <b>پرداخت آنلاین</b>\n\nسفارش #{order_id} — یکی از درگاه‌های زیر را بزنید:\n\n<i>پس از پرداخت موفق، لینک اشتراک ارسال می‌شود.</i>",
                'description' => 'پیام نمایش لیست درگاه‌های پرداخت آنلاین. متغیر: {order_id}',
            ],
            [
                'key' => 'msg_stripe_payment_header',
                'category' => 'messages',
                'title' => 'پیام: هدر پرداخت Stripe',
                'content' => '💳 <b>پرداخت با کارت اعتباری (Stripe)</b>',
                'description' => 'عنوان پیام پرداخت Stripe',
            ],
            [
                'key' => 'msg_stripe_payment_desc',
                'category' => 'messages',
                'title' => 'پیام: توضیحات پرداخت Stripe',
                'content' => 'این درگاه نیاز به تکمیل فرم کارت در صفحه امن دارد.',
                'description' => 'توضیحات پرداخت Stripe',
            ],
            [
                'key' => 'msg_stripe_login_info',
                'category' => 'messages',
                'title' => 'پیام: اطلاعات ورود پنل',
                'content' => "👤 <b>اطلاعات ورود به پنل:</b>\n▫️ ایمیل: <code>{email}</code>\n▫️ رمز: <code>{password}</code>",
                'description' => 'نمایش اطلاعات ورود به پنل برای Stripe. متغیرها: {email}, {password}',
            ],
            [
                'key' => 'msg_stripe_payment_link',
                'category' => 'messages',
                'title' => 'پیام: لینک پرداخت',
                'content' => "🔗 لینک پرداخت:\n{payment_url}",
                'description' => 'نمایش لینک پرداخت. متغیر: {payment_url}',
            ],
            [
                'key' => 'msg_payment_complete_instruction',
                'category' => 'instructions',
                'title' => 'راهنما: بعد از تکمیل پرداخت',
                'content' => 'بعد از تکمیل پرداخت، دکمهٔ زیر را بزنید.',
                'description' => 'دستورالعمل بعد از تکمیل پرداخت آنلاین',
            ],
            [
                'key' => 'msg_gateway_list_sent',
                'category' => 'notifications',
                'title' => 'اعلان: ارسال لیست درگاه‌ها',
                'content' => "✅ لیست درگاه‌های پرداخت ارسال شد.\n\nاز پیام بعدی یکی از درگاه‌ها (مثل PayPal، Stripe و...) را انتخاب کنید.",
                'description' => 'پیام بعد از ارسال لیست درگاه‌های پرداخت',
            ],
            [
                'key' => 'msg_qr_payment',
                'category' => 'messages',
                'title' => 'پیام: QR کد پرداخت',
                'content' => '🔔 QR کد پرداخت — در صورت نیاز ابتدا پرداخت را انجام دهید.',
                'description' => 'کپشن عکس QR کد پرداخت',
            ],
            [
                'key' => 'msg_payment_after_wallet',
                'category' => 'messages',
                'title' => 'پیام: پس از کسر از کیف پول',
                'content' => '✅ مبلغ تمدید از کیف پول کسر شد. برای تکمیل پرداخت، *پیام بعدی* (دکمه‌های درگاه) را ببینید.',
                'description' => 'پیام بعد از کسر مبلغ از کیف پول در تمدید',
            ],

            // راهنماها - Instructions
            [
                'key' => 'inst_discount_prompt',
                'category' => 'instructions',
                'title' => 'راهنما: درخواست کد تخفیف',
                'content' => '🎫 لطفاً کد تخفیف خود را ارسال کنید:',
                'description' => 'پیام درخواست کد تخفیف از کاربر',
            ],
            [
                'key' => 'inst_payment_after_gateway',
                'category' => 'instructions',
                'title' => 'راهنما: بعد از انتخاب درگاه',
                'content' => 'بعد از پرداخت، دکمهٔ زیر را بزنید تا فعال‌سازی بررسی شود.',
                'description' => 'پیام راهنمای بعد از ارسال لینک پرداخت آنلاین',
            ],

            // خطاها - Errors
            [
                'key' => 'err_discount_invalid',
                'category' => 'errors',
                'title' => 'خطا: کد تخفیف نامعتبر',
                'content' => '❌ کد تخفیف نامعتبر است.',
                'description' => 'پیام خطا برای کد تخفیف نامعتبر',
            ],
            [
                'key' => 'err_discount_inactive',
                'category' => 'errors',
                'title' => 'خطا: کد تخفیف غیرفعال',
                'content' => '❌ کد تخفیف غیرفعال است.',
                'description' => 'پیام خطا برای کد تخفیف غیرفعال',
            ],
            [
                'key' => 'err_discount_expired',
                'category' => 'errors',
                'title' => 'خطا: کد تخفیف منقضی',
                'content' => '❌ کد تخفیف منقضی شده است.',
                'description' => 'پیام خطا برای کد تخفیف منقضی شده',
            ],

            // تاییدها - Confirmations
            [
                'key' => 'conf_service_activated',
                'category' => 'confirmations',
                'title' => 'تایید: فعال‌سازی سرویس',
                'content' => '✅ سرویس شما ({plan_name}) فعال شد.',
                'description' => 'پیام تایید فعال‌سازی سرویس. متغیر: {plan_name}',
            ],
            [
                'key' => 'conf_discount_applied',
                'category' => 'confirmations',
                'title' => 'تایید: اعمال کد تخفیف',
                'content' => '✅ کد تخفیف «{discount_code}» اعمال شد. مبلغ تخفیف: {discount_amount} تومان',
                'description' => 'پیام تایید اعمال کد تخفیف. متغیرها: {discount_code}, {discount_amount}',
            ],

            // تمدید - Renewals
            [
                'key' => 'btn_renew_card',
                'category' => 'buttons',
                'title' => 'دکمه: تمدید با کارت',
                'content' => '💳 تمدید با کارت به کارت',
                'description' => 'متن دکمه تمدید با کارت به کارت',
            ],
            [
                'key' => 'btn_renew_online',
                'category' => 'buttons',
                'title' => 'دکمه: تمدید آنلاین',
                'content' => '🌐 تمدید با پرداخت آنلاین ارزی / کریپتو',
                'description' => 'متن دکمه تمدید با پرداخت آنلاین',
            ],
            [
                'key' => 'btn_renew_plisio',
                'category' => 'buttons',
                'title' => 'دکمه: تمدید با Plisio',
                'content' => '💎 تمدید با Plisio',
                'description' => 'متن دکمه تمدید با Plisio',
            ],

            // پیام‌های درگاه پرداخت - Gateway Messages
            [
                'key' => 'msg_payment_redirect_sent',
                'category' => 'notifications',
                'title' => 'اعلان: لینک پرداخت ارسال شد',
                'content' => 'لینک پرداخت ارسال شد؛ بعد از پرداخت «بررسی کن» را بزنید.',
                'description' => 'پیام بعد از ارسال لینک redirect درگاه پرداخت',
            ],
            [
                'key' => 'msg_payment_gateway_data',
                'category' => 'messages',
                'title' => 'پیام: اطلاعات پرداخت درگاه',
                'content' => "💳 اطلاعات پرداخت درگاه:\n<code>{data}</code>",
                'description' => 'نمایش اطلاعات پرداخت درگاه (مثل آدرس کیف پول). متغیر: {data}',
            ],
            [
                'key' => 'msg_payment_pending_check',
                'category' => 'notifications',
                'title' => 'اعلان: پرداخت در انتظار تایید',
                'content' => "⏳ پرداخت این سفارش هنوز در XMPlus نهایی نشده است.\nبعد از تکمیل پرداخت، دکمه «✅ پرداخت کردم، بررسی کن» را بزنید.",
                'description' => 'پیام زمانی که پرداخت هنوز تکمیل نشده (pending)',
            ],
            [
                'key' => 'msg_payment_still_pending',
                'category' => 'notifications',
                'title' => 'اعلان: پرداخت همچنان در انتظار',
                'content' => '⏳ پرداخت این سفارش هنوز در XMPlus نهایی نشده است (فاکتور «{invoice_id}» همچنان Pending است).\n\nاین معمولاً به این دلیل است که پرداخت در درگاه (PayPal، Stripe و...) هنوز تکمیل نشده یا callback به پنل ارسال نشده است.\n\n▫️ اگر پرداخت را در درگاه تکمیل کردید، لطفاً چند دقیقه صبر کنید و سپس دکمهٔ «✅ پرداخت کردم، بررسی کن» را دوباره بزنید.\n▫️ اگر پرداخت را تکمیل نکردید، به لینک پرداخت برگردید و آن را تمام کنید.',
                'description' => 'پیام تفصیلی برای پرداخت pending. متغیر: {invoice_id}',
            ],
            [
                'key' => 'msg_payment_session_expired',
                'category' => 'errors',
                'title' => 'خطا: جلسه پرداخت منقضی شده',
                'content' => '❌ جلسه پرداخت منقضی شده است. لطفاً خرید را مجدداً شروع کنید.',
                'description' => 'پیام خطا برای جلسه منقضی شده',
            ],
            [
                'key' => 'btn_back_to_methods',
                'category' => 'buttons',
                'title' => 'دکمه: بازگشت به روش‌ها',
                'content' => '⬅️ بازگشت به روش‌ها',
                'description' => 'متن دکمه بازگشت به روش‌های پرداخت',
            ],
            [
                'key' => 'btn_cancel',
                'category' => 'buttons',
                'title' => 'دکمه: انصراف',
                'content' => '❌ انصراف',
                'description' => 'متن دکمه انصراف',
            ],

            // پیام‌های خرید و انتخاب پلن - Purchase Messages
            [
                'key' => 'msg_select_plan',
                'category' => 'messages',
                'title' => 'پیام: انتخاب پلن برای خرید',
                'content' => "🛒 خرید سرویس VPN (XMPlus)\n\nیک پکیج را از دکمه‌های زیر انتخاب کنید.\nقیمت نهایی به تومان مطابق «پلن فروشگاه» است (نه قیمت خام پنل).",
                'description' => 'پیام لیست پلن‌ها برای خرید',
            ],
            [
                'key' => 'msg_plans_unavailable',
                'category' => 'errors',
                'title' => 'خطا: پلن‌ها در دسترس نیست',
                'content' => '⚠️ لیست پکیج‌های پنل موقتاً در دسترس نیست؛ اگر پلن‌ها pid دارند باز هم می‌توانید خرید کنید.',
                'description' => 'پیام خطا برای عدم دسترسی به لیست پلن‌ها',
            ],
            [
                'key' => 'msg_no_plans',
                'category' => 'errors',
                'title' => 'خطا: هیچ پلنی موجود نیست',
                'content' => '❌ در حال حاضر هیچ پلنی برای خرید موجود نیست.',
                'description' => 'پیام خطا برای عدم وجود پلن',
            ],
        ];

        foreach ($messages as $message) {
            BotMessage::updateOrCreate(
                ['key' => $message['key']],
                $message
            );
        }

        $this->command->info('✅ پیام‌های پیش‌فرض ربات با موفقیت ایجاد شدند.');
    }
}
