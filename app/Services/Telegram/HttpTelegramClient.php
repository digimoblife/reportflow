<?php

namespace App\Services\Telegram;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;

/**
 * Bot API over HTTPS. The token lives in the request URL, so every transport failure is caught
 * and re-thrown as a TelegramApiException that carries no URL and no `previous` exception.
 */
final class HttpTelegramClient implements TelegramClient
{
    public function sendMessage(int $chatId, string $text, ?int $replyToMessageId = null, ?array $inlineKeyboard = null): int
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'disable_web_page_preview' => true];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        if ($replyToMessageId !== null) {
            $payload['reply_parameters'] = ['message_id' => $replyToMessageId, 'allow_sending_without_reply' => true];
        }

        return (int) $this->call('sendMessage', $payload)['message_id'];
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $inlineKeyboard = null): void
    {
        $payload = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($inlineKeyboard !== null) {
            $payload['reply_markup'] = ['inline_keyboard' => $inlineKeyboard];
        }

        $this->call('editMessageText', $payload);
    }

    public function sendDocument(int $chatId, string $filename, #[\SensitiveParameter] string $contents, ?string $caption = null, ?array $inlineKeyboard = null): int
    {
        $fields = ['chat_id' => (string) $chatId];

        if ($caption !== null) {
            $fields['caption'] = $caption;
        }

        if ($inlineKeyboard !== null) {
            $fields['reply_markup'] = json_encode(['inline_keyboard' => $inlineKeyboard], JSON_THROW_ON_ERROR);
        }

        return (int) $this->call('sendDocument', $fields, [$filename, $contents])['message_id'];
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        $this->call('answerCallbackQuery', array_filter(['callback_query_id' => $callbackQueryId, 'text' => $text], fn ($v) => $v !== null));
    }

    public function setWebhook(string $url, #[\SensitiveParameter] string $secretToken, array $allowedUpdates): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => $allowedUpdates,
        ]);
    }

    public function setMyCommands(array $commands, ?string $languageCode = null): void
    {
        $payload = ['commands' => $commands];

        if ($languageCode !== null) {
            $payload['language_code'] = $languageCode;
        }

        $this->call('setMyCommands', $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{0: string, 1: string}|null  $file  filename and bytes of the `document` part (switches the request to multipart)
     * @return array<string, mixed>
     *
     * @throws TelegramApiException
     */
    private function call(string $method, array $payload, ?array $file = null): array
    {
        $token = config('telegram.token');

        if (! is_string($token) || $token === '') {
            throw TelegramApiException::notConfigured($method);
        }

        try {
            $request = Http::baseUrl(rtrim((string) config('telegram.api_base'), '/').'/bot'.$token.'/')
                ->connectTimeout((int) config('telegram.connect_timeout', 3))
                ->timeout($file === null ? (int) config('telegram.timeout', 5) : (int) config('telegram.upload_timeout', 30))
                ->acceptJson();

            $response = $file === null
                ? $request->asJson()->post($method, $payload)
                : $request->attach('document', $file[1], $file[0])->post($method, $payload);
        } catch (HttpClientException|GuzzleException) {
            // Message and trace of these exceptions contain the URL, hence the token. Drop them.
            throw TelegramApiException::transport($method);
        }

        if ($response->failed() || $response->json('ok') !== true) {
            throw TelegramApiException::fromResponse(
                $method,
                $response->status(),
                is_string($response->json('description')) ? $response->json('description') : null,
                is_numeric($response->json('parameters.retry_after')) ? (int) $response->json('parameters.retry_after') : null,
            );
        }

        $result = $response->json('result');

        return is_array($result) ? $result : [];
    }
}
