<?php

namespace App\Support;

use App\Models\BotMessage;

class BotMessageHelper
{
    /**
     * دریافت متن دکمه
     */
    public static function button(string $key, string $default = '', array $vars = []): string
    {
        return BotMessage::get($key, $default, $vars);
    }

    /**
     * دریافت متن پیام
     */
    public static function message(string $key, string $default = '', array $vars = []): string
    {
        return BotMessage::get($key, $default, $vars);
    }

    /**
     * دریافت متن خطا
     */
    public static function error(string $key, string $default = '', array $vars = []): string
    {
        return BotMessage::get($key, $default, $vars);
    }

    /**
     * دریافت متن تایید
     */
    public static function confirmation(string $key, string $default = '', array $vars = []): string
    {
        return BotMessage::get($key, $default, $vars);
    }

    /**
     * دریافت متن راهنما
     */
    public static function instruction(string $key, string $default = '', array $vars = []): string
    {
        return BotMessage::get($key, $default, $vars);
    }

    /**
     * فرمت کردن مبلغ به تومان
     */
    public static function formatAmount(int|float $amount): string
    {
        return number_format($amount, 0, '.', ',');
    }

    /**
     * متغیرهای استاندارد برای سفارش
     */
    public static function orderVariables($order): array
    {
        if (!$order) {
            return [];
        }

        $vars = [
            'order_id' => $order->id,
            'amount' => self::formatAmount($order->amount),
        ];

        if ($order->plan) {
            $vars['plan_name'] = $order->plan->name;
            $vars['plan_price'] = self::formatAmount($order->plan->price);
        }

        if ($order->user) {
            $vars['username'] = $order->user->name ?? 'کاربر';
            $vars['user_id'] = $order->user->id;
        }

        if ($order->discount_code_id && $order->discountCode) {
            $vars['discount_code'] = $order->discountCode->code;
            $vars['discount_amount'] = self::formatAmount($order->discount_amount ?? 0);
        }

        return $vars;
    }

    /**
     * متغیرهای استاندارد برای کاربر
     */
    public static function userVariables($user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'username' => $user->name ?? 'کاربر',
            'user_id' => $user->id,
            'balance' => self::formatAmount($user->balance ?? 0),
        ];
    }
}
