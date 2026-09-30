<?php

use App\Services\Telegram\HttpTelegramClient;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramMessenger;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeSecrets;

beforeEach(function () {
    $this->token = FakeSecrets::telegramBotToken();
    config(['telegram.token' => $this->token]);
    $this->client = new HttpTelegramClient;
});

it('sends a message and returns the message id', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);

    $id = $this->client->sendMessage(555001, 'halo', 12);

    expect($id)->toBe(77);

    Http::assertSent(function (Request $request) {
        return str_ends_with($request->url(), '/sendMessage')
            && $request['chat_id'] === 555001
            && $request['text'] === 'halo'
            && $request['reply_parameters']['message_id'] === 12
            && ! isset($request['parse_mode']);
    });
});

it('refuses to call the API without a token', function () {
    config(['telegram.token' => '']);
    Http::fake();

    expect(fn () => $this->client->sendMessage(1, 'x'))->toThrow(TelegramApiException::class, 'not configured');
    Http::assertNothingSent();
});

it('turns API errors into exceptions with status, description and retry_after', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests: retry after 7', 'parameters' => ['retry_after' => 7]], 429)]);

    try {
        $this->client->sendMessage(1, 'x');
        $this->fail('expected exception');
    } catch (TelegramApiException $e) {
        expect($e->httpStatus)->toBe(429)->and($e->retryAfter)->toBe(7)->and($e->isRetryable())->toBeTrue();
    }
});

it('classifies errors: 400/403 permanent, 429/5xx/transport retryable', function () {
    expect(TelegramApiException::fromResponse('m', 400, 'x')->isRetryable())->toBeFalse()
        ->and(TelegramApiException::fromResponse('m', 403, 'blocked')->isPermanent())->toBeTrue()
        ->and(TelegramApiException::fromResponse('m', 429, 'x', 3)->isRetryable())->toBeTrue()
        ->and(TelegramApiException::fromResponse('m', 502, 'x')->isRetryable())->toBeTrue()
        ->and(TelegramApiException::transport('m')->isRetryable())->toBeTrue()
        ->and(TelegramApiException::notConfigured('m')->isRetryable())->toBeFalse()
        ->and(TelegramApiException::fromResponse('m', 400, 'Bad Request: message is not modified')->messageNotModified())->toBeTrue();
});

function transportException(string $kind, string $url): Throwable
{
    return match ($kind) {
        'connection' => new ConnectionException("cURL error 6: Could not resolve host: api.telegram.org for $url"),
        'request' => new RequestException(new Illuminate\Http\Client\Response(new Response(500, [], "upstream failed for $url"))),
        'guzzle' => new ConnectException("Connection refused for $url", new GuzzleHttp\Psr7\Request('POST', $url)),
    };
}

it('never lets the bot token escape through transport failures', function (string $kind) {
    $token = $this->token;
    $exception = transportException($kind, "https://api.telegram.org/bot{$token}/sendMessage");
    Http::fake(fn () => throw $exception);
    config(['app.debug' => true]);

    $logs = captureLogs();
    $caught = null;

    try {
        $this->client->sendMessage(555001, 'halo');
    } catch (TelegramApiException $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(TelegramApiException::class)
        ->and($caught->getPrevious())->toBeNull()
        ->and($caught->getMessage())->not->toContain($token)
        ->and($caught->getTraceAsString())->not->toContain($token)
        ->and((string) $caught)->not->toContain($token);

    // Through the app's own error paths: logged by the messenger, reported, and rendered with debug on.
    (new TelegramMessenger($this->client))->trySend(555001, 'halo');
    report($caught);
    $rendered = app(ExceptionHandler::class)->render(request(), $caught)->getContent();

    expect(loggedText($logs))->not->toContain($token)
        ->and($rendered)->not->toContain($token)
        ->and(json_encode($logs->getRecords()))->not->toContain($token);
})->with(['connection', 'request', 'guzzle']);

it('sends setWebhook and setMyCommands to the API', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    $this->client->setWebhook('https://example.test/hook', 'secret_value-1', ['message', 'edited_message']);
    $this->client->setMyCommands([['command' => 'start', 'description' => 'Mulai']], 'en');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook') && $r['allowed_updates'] === ['message', 'edited_message'] && $r['secret_token'] === 'secret_value-1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setMyCommands') && $r['language_code'] === 'en');
});

it('sends inline keyboards on send and edit, and leaves them alone when none is given', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]])]);
    $keyboard = [[['text' => 'Undo', 'callback_data' => 'a:1:undo']]];

    $this->client->sendMessage(1, 'hai', null, $keyboard);
    $this->client->editMessageText(1, 5, 'baru', $keyboard);
    $this->client->editMessageText(1, 5, 'tanpa tombol dihapus', []);
    $this->client->editMessageText(1, 5, 'tombol dibiarkan');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['reply_markup'] === ['inline_keyboard' => $keyboard]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['text'] === 'baru' && $r['reply_markup'] === ['inline_keyboard' => $keyboard]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['text'] === 'tanpa tombol dihapus' && $r['reply_markup'] === ['inline_keyboard' => []]);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/editMessageText') && $r['text'] === 'tombol dibiarkan' && ! isset($r['reply_markup']));
});

it('answers a callback query, with and without a toast', function () {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => true])]);

    $this->client->answerCallbackQuery('cb-9', 'Siap');
    $this->client->answerCallbackQuery('cb-10');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb-9' && $r['text'] === 'Siap');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/answerCallbackQuery') && $r['callback_query_id'] === 'cb-10' && ! isset($r['text']));
});
