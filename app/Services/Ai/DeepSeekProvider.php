<?php

namespace App\Services\Ai;

use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * DeepSeek chat completions (OpenAI-compatible). JSON mode = response_format json_object; DeepSeek requires
 * the word "json" and an example in the prompt, and may occasionally return empty content (the caller
 * treats that as an invalid answer and retries once).
 *
 * Like HttpTelegramClient: every transport failure is re-thrown as AiProviderException WITHOUT previous
 * exception, URL or headers (the Authorization header carries the API key).
 */
final class DeepSeekProvider implements BatchAiProvider
{
    public function name(): string
    {
        return 'deepseek';
    }

    public function complete(AiRequest $request): AiResponse
    {
        $key = $this->apiKey();
        $started = hrtime(true);

        try {
            $response = $this->client($key)->post('/chat/completions', $this->body($request));
        } catch (HttpClientException|GuzzleException) {
            throw new AiProviderException('DeepSeek request failed (connection error or timeout).');
        }

        return $this->toResponse($response, (int) round((hrtime(true) - $started) / 1e6));
    }

    /**
     * Concurrent requests (eval:run). Each result is an AiResponse or an AiProviderException; nothing is thrown
     * for a single failed request. The same redaction of transport errors as complete() applies.
     *
     * @param  list<AiRequest>  $requests
     * @return list<AiResponse|AiProviderException>
     */
    public function completeMany(array $requests, int $concurrency): array
    {
        $key = $this->apiKey();
        $results = [];

        foreach (array_chunk($requests, max(1, $concurrency), true) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $key): array {
                    $calls = [];

                    foreach ($chunk as $i => $request) {
                        $calls[] = $pool->as((string) $i)
                            ->baseUrl(rtrim((string) config('ai.deepseek.base_url'), '/'))
                            ->withToken($key)
                            ->connectTimeout((int) config('ai.deepseek.connect_timeout', 5))
                            ->timeout((int) config('ai.deepseek.timeout', 25))
                            ->acceptJson()
                            ->asJson()
                            ->post('/chat/completions', $this->body($request));
                    }

                    return $calls;
                });
            } catch (HttpClientException|GuzzleException) {
                foreach (array_keys($chunk) as $i) {
                    $results[$i] = new AiProviderException('DeepSeek request failed (connection error or timeout).');
                }

                continue;
            }

            foreach (array_keys($chunk) as $i) {
                $response = $responses[(string) $i] ?? null;

                if (! $response instanceof Response) {
                    // A ConnectionException (or missing entry): never keep its message, it names the URL.
                    $results[$i] = new AiProviderException($response instanceof ConnectionException || $response === null
                        ? 'DeepSeek request failed (connection error or timeout).'
                        : 'DeepSeek request failed.');

                    continue;
                }

                try {
                    $results[$i] = $this->toResponse($response, $this->transferMs($response));
                } catch (AiProviderException $e) {
                    $results[$i] = $e;
                }
            }
        }

        ksort($results);

        return array_values($results);
    }

    private function apiKey(): string
    {
        $key = config('ai.deepseek.api_key');

        if (! is_string($key) || $key === '') {
            throw new AiProviderException('DeepSeek API key is not configured.');
        }

        return $key;
    }

    private function client(string $key): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ai.deepseek.base_url'), '/'))
            ->withToken($key)
            ->connectTimeout((int) config('ai.deepseek.connect_timeout', 5))
            ->timeout((int) config('ai.deepseek.timeout', 25))
            ->acceptJson()
            ->asJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function body(AiRequest $request): array
    {
        $body = [
            'model' => (string) config('ai.deepseek.model'),
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

        return $body;
    }

    private function toResponse(Response $response, ?int $latencyMs): AiResponse
    {
        if ($response->failed()) {
            throw new AiProviderException('DeepSeek request failed with HTTP '.$response->status().'.');
        }

        $content = $response->json('choices.0.message.content');
        $model = $response->json('model');

        return new AiResponse(
            content: is_string($content) ? $content : '',
            model: is_string($model) ? $model : (string) config('ai.deepseek.model'),
            tokensInput: is_numeric($response->json('usage.prompt_tokens')) ? (int) $response->json('usage.prompt_tokens') : null,
            tokensOutput: is_numeric($response->json('usage.completion_tokens')) ? (int) $response->json('usage.completion_tokens') : null,
            latencyMs: $latencyMs,
        );
    }

    private function transferMs(Response $response): ?int
    {
        $seconds = $response->transferStats?->getTransferTime();

        return $seconds === null ? null : (int) round($seconds * 1000);
    }
}
