<?php

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\DeepSeekProvider;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Built at runtime; not a real key. The test suite has no way to reach api.deepseek.com.
    $this->key = 'sk-'.'TESTKEY'.'0123456789abcdef'.'ghij';
    config(['ai.deepseek.api_key' => $this->key, 'ai.deepseek.base_url' => 'https://api.deepseek.test', 'ai.deepseek.model' => 'deepseek-flash']);
    $this->request = new AiRequest('worklog_extraction', 'worklog_extraction@v1', 'SYSTEM PROMPT with json', '{"message":"halo"}');
});

function chatReply(string $content = '{"items":[],"clarification_needed":null}'): array
{
    return ['id' => 'x', 'model' => 'deepseek-flash', 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 321, 'completion_tokens' => 45, 'total_tokens' => 366]];
}

it('calls chat completions in JSON mode and maps the reply and token usage', function () {
    Http::fake(['api.deepseek.test/*' => Http::response(chatReply())]);

    $response = (new DeepSeekProvider)->complete($this->request);

    expect($response->content)->toBe('{"items":[],"clarification_needed":null}')
        ->and($response->model)->toBe('deepseek-flash')
        ->and($response->tokensInput)->toBe(321)
        ->and($response->tokensOutput)->toBe(45)
        ->and($response->latencyMs)->toBeInt();

    Http::assertSent(function (Request $r) {
        $body = $r->data();

        return $r->url() === 'https://api.deepseek.test/chat/completions'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer '.$this->key)
            && $body['model'] === 'deepseek-flash'
            && $body['response_format'] === ['type' => 'json_object']
            && $body['temperature'] === 0
            && $body['stream'] === false
            && $body['max_tokens'] === 2048
            && $body['messages'] === [['role' => 'system', 'content' => 'SYSTEM PROMPT with json'], ['role' => 'user', 'content' => '{"message":"halo"}']];
    });
});

it('can skip JSON mode', function () {
    Http::fake(['api.deepseek.test/*' => Http::response(chatReply('plain'))]);

    (new DeepSeekProvider)->complete(new AiRequest('x', 'x@v1', 's', 'u', jsonMode: false));

    Http::assertSent(fn (Request $r) => ! isset($r->data()['response_format']));
});

it('returns empty content as an empty string (DeepSeek documents that this can happen)', function () {
    Http::fake(['api.deepseek.test/*' => Http::response(['choices' => [['message' => ['content' => null]]], 'usage' => []])]);

    $response = (new DeepSeekProvider)->complete($this->request);

    expect($response->content)->toBe('')->and($response->tokensInput)->toBeNull();
});

it('refuses to call without an API key', function () {
    config(['ai.deepseek.api_key' => '']);
    Http::fake();

    expect(fn () => (new DeepSeekProvider)->complete($this->request))->toThrow(AiProviderException::class, 'not configured');
    Http::assertNothingSent();
});

it('turns HTTP errors into exceptions that carry only the status', function (int $status) {
    Http::fake(['api.deepseek.test/*' => Http::response(['error' => ['message' => 'echo of user text and '.$this->key]], $status)]);

    try {
        (new DeepSeekProvider)->complete($this->request);
        $this->fail('expected exception');
    } catch (AiProviderException $e) {
        expect($e->getMessage())->toBe("DeepSeek request failed with HTTP {$status}.")
            ->and($e->getPrevious())->toBeNull()
            ->and((string) $e)->not->toContain($this->key);
    }
})->with([400, 401, 402, 422, 429, 500, 503]);

it('never lets the API key escape through transport failures', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: timed out for https://api.deepseek.test/chat/completions with Authorization: Bearer '.$this->key));
    config(['app.debug' => true]);
    $logs = captureLogs();

    try {
        (new DeepSeekProvider)->complete($this->request);
        $this->fail('expected exception');
    } catch (AiProviderException $e) {
        report($e);
        $rendered = app(ExceptionHandler::class)->render(request(), $e)->getContent();

        expect($e->getPrevious())->toBeNull()
            ->and($e->getMessage())->not->toContain($this->key)
            ->and($e->getTraceAsString())->not->toContain($this->key)
            ->and($rendered)->not->toContain($this->key)
            ->and(json_encode($logs->getRecords()))->not->toContain($this->key);
    }
});
