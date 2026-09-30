<?php

namespace Tests\Support;

/**
 * Builders for Telegram Bot API updates used in webhook tests.
 */
final class TelegramPayload
{
    private static int $updateId = 1;

    /**
     * @param  array<string, mixed>  $extra  extra message fields (photo, voice, caption, ...)
     * @return array<string, mixed>
     */
    public static function message(?string $text, int $from = 555001, int $messageId = 10, array $extra = []): array
    {
        $message = [
            'message_id' => $messageId,
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Test'],
            'chat' => ['id' => $from, 'type' => 'private'],
            'date' => 1_780_000_000,
        ] + ($text === null ? [] : ['text' => $text]) + $extra;

        return ['update_id' => self::$updateId++, 'message' => $message];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public static function edited(string $text, int $from = 555001, int $messageId = 10, int $editDate = 1_780_000_100, array $extra = []): array
    {
        $payload = self::message($text, $from, $messageId, ['edit_date' => $editDate] + $extra);
        $payload['edited_message'] = $payload['message'];
        unset($payload['message']);

        return $payload;
    }
}
