<?php

namespace App\Support;

final class TelegramMarkdownV2
{
    public static function escape(string $text): string
    {
        $chars = ['_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        $text = str_replace('\\', '\\\\', $text);

        return str_replace($chars, array_map(fn ($char) => '\\'.$char, $chars), $text);
    }
}
