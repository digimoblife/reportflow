<?php

namespace App\Services\Telegram;

/**
 * Builds inline keyboards (rows of buttons) for the Bot API.
 */
final class Keyboard
{
    /**
     * @return array{text: string, callback_data: string}
     */
    public static function button(string $text, CallbackData $data): array
    {
        return ['text' => $text, 'callback_data' => $data->encode()];
    }

    /**
     * Rows of at most $perRow buttons.
     *
     * @param  list<array{text: string, callback_data: string}>  $buttons
     * @return list<list<array{text: string, callback_data: string}>>
     */
    public static function rows(array $buttons, int $perRow = 2): array
    {
        return array_chunk($buttons, max(1, $perRow));
    }
}
