<?php

namespace App\Services\Ai;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;

/**
 * DeepSeek chat completions (OpenAI-compatible). JSON mode = response_format json_object; DeepSeek requires
 * the word "json" and an example in the prompt, and may occasionally return empty content (the caller
 * treats that as an invalid answer and retries once).
 *
 * Like HttpTelegramClient: every transport failure is re-thrown as AiProviderException WITHOUT previous
 * exception, URL or headers (the Authorization header carries the API key).
 */
final class DeepSeekProvider implements AiProvider
{
    public function name(): string
    {
        return 'deepseek';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $key = config('ai.deepseek.api_key');

        if (! is_string($key) || $key === '') {
            throw new AiProviderException('DeepSeek API key is not configured.');
        }

        $model = (string) config('ai.deepseek.model');
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->user],
            ],
            'temperature' => 0,
            'max_tokens' => (int) config('ai.deepseek.max_tokens', 2048),
            'stream' => false,
        ];

        if ($request->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        $started = hrtime(true);

        try {
            $response = Http::baseUrl(rtrim((string) config('ai.deepseek.base_url'), '/'))
                ->withToken($key)
                ->connectTimeout((int) config('ai.deepseek.connect_timeout', 5))
                ->timeout((int) config('ai.deepseek.timeout', 25))
                ->acceptJson()
                ->asJson()
                ->post('/chat/completions', $body);
        } catch (HttpClientException|GuzzleException) {
            throw new AiProviderException('DeepSeek request failed (connection error or timeout).');
        }

        if ($response->failed()) {
            throw new AiProviderException('DeepSeek request failed with HTTP '.$response->status().'.');
        }

        $content = $response->json('choices.0.message.content');

        return new AiResponse(
            content: is_string($content) ? $content : '',
            model: is_string($response->json('model')) ? $response->json('model') : $model,
            tokensInput: is_numeric($response->json('usage.prompt_tokens')) ? (int) $response->json('usage.prompt_tokens') : null,
            tokensOutput: is_numeric($response->json('usage.completion_tokens')) ? (int) $response->json('usage.completion_tokens') : null,
            latencyMs: (int) round((hrtime(true) - $started) / 1e6),
        );
    }
}
