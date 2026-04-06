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
                'key' => 'msg_stripe_payment',
                'category' => 'messages',
                'title' => 'پیام: پرداخت Stripe',
                'content' => "💳 <b>پرداخت با کارت اعتباری (Stripe)</b>\n\nاین درگاه نیاز به تکمیل فرم کارت در صفحه امن دارد.",
                'description' => 'پیام نمایش دستورالعمل پرداخت Stripe (ادامه در کد)',
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
