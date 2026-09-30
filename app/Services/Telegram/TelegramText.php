<?php

namespace App\Services\Telegram;

/**
 * Keeps outgoing text within Telegram's limit (4096 characters after parsing).
 */
final class TelegramText
{
    public static function fit(string $text, ?int $limit = null): string
    {
        $limit ??= (int) config('telegram.max_message_length', 4096);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)).'…';
    }
}
