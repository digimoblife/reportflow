<?php

use App\Http\Middleware\RequireSecureInProduction;
use App\Models\User;
use App\Services\Telegram\HttpTelegramClient;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramIngestionService;
use App\Services\Telegram\TelegramUpdate;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramPayload;

beforeEach(function () {
    Queue::fake();
});

function nothingHappened(): void
{
    expect(storedMessages())->toHaveCount(0)
        ->and(fakeTelegram()->sent)->toBe([])
        ->and(fakeTelegram()->edits)->toBe([]);
    Queue::assertNothingPushed();
}

it('rejects requests without the secret header with a bare 404', function () {
    registerTelegramUser();

    postTelegram(TelegramPayload::message('halo'), headers: [])->assertNotFound();

    nothingHappened();
});

it('rejects requests with a wrong secret', function (string $secret) {
    registerTelegramUser();

    postTelegram(TelegramPayload::message('halo'), ['X-Telegram-Bot-Api-Secret-Token' => $secret])->assertNotFound();

    nothingHappened();
})->with(['wrong value' => 'nope', 'prefix of the real one' => fn () => substr((string) config('telegram.secret_token'), 0, 8), 'real one plus suffix' => fn () => config('telegram.secret_token').'x', 'empty' => '']);

it('rejects everything when no secret is configured (fail closed)', function () {
    config(['telegram.secret_token' => '']);
    registerTelegramUser();

    postTelegram(TelegramPayload::message('halo'), ['X-Telegram-Bot-Api-Secret-Token' => ''])->assertNotFound();

    nothingHappened();
});

it('answers 404 on a wrong path', function () {
    $this->postJson('/telegram/webhook', TelegramPayload::message('halo'))->assertNotFound();
});

it('does not register the route when the path is missing or too short', function (string $path) {
    withAppEnvironment('local', ['TELEGRAM_WEBHOOK_PATH' => $path], function () use ($path) {
        if ($path !== '') {
            $this->postJson('/'.$path, [], ['X-Telegram-Bot-Api-Secret-Token' => (string) config('telegram.secret_token')])->assertNotFound();
        }

        expect(app('router')->has('telegram.webhook'))->toBeFalse();
    });
})->with(['empty' => '', 'short' => 'short-path', 'bad characters' => str_repeat('ab/', 12)]);

it('accepts a valid secret from an unregistered sender but stores nothing and says nothing', function () {
    postTelegram(TelegramPayload::message('halo', from: 999888))->assertOk();

    nothingHappened();
    expect(User::query()->count())->toBe(0);
});

it('logs an unregistered sender id at most once per hour and never the text', function () {
    $logs = captureLogs();

    foreach (range(1, 3) as $i) {
        postTelegram(TelegramPayload::message('rahasia '.$i, from: 999888, messageId: $i))->assertOk();
    }
    postTelegram(TelegramPayload::message('lain', from: 777666))->assertOk();

    $records = collect($logs->getRecords())->where('message', 'telegram.unregistered_sender');

    expect($records->pluck('context.telegram_user_id')->all())->toBe([999888, 777666])
        ->and(loggedText($logs))->not->toContain('rahasia');
});

it('ignores unsupported update types, malformed payloads, groups and bots without side effects', function (array $payload) {
    registerTelegramUser();

    postTelegram($payload)->assertOk();

    nothingHappened();
})->with([
    'callback query' => [['update_id' => 5, 'callback_query' => ['id' => '1', 'from' => ['id' => 555001]]]],
    'empty object' => [[]],
    'message without chat' => [['update_id' => 6, 'message' => ['message_id' => 1, 'from' => ['id' => 555001], 'text' => 'x']]],
    'text is not a string' => [['update_id' => 7, 'message' => ['message_id' => 1, 'from' => ['id' => 555001, 'is_bot' => false], 'chat' => ['id' => 555001, 'type' => 'private'], 'text' => ['x']]]],
    'group chat' => [['update_id' => 8, 'message' => ['message_id' => 1, 'from' => ['id' => 555001, 'is_bot' => false], 'chat' => ['id' => -100200, 'type' => 'supergroup'], 'text' => 'halo']]],
    'sent by a bot' => [['update_id' => 9, 'message' => ['message_id' => 1, 'from' => ['id' => 555001, 'is_bot' => true], 'chat' => ['id' => 555001, 'type' => 'private'], 'text' => 'halo']]],
    'chat id differs from sender' => [['update_id' => 10, 'message' => ['message_id' => 1, 'from' => ['id' => 555001, 'is_bot' => false], 'chat' => ['id' => 42, 'type' => 'private'], 'text' => 'halo']]],
]);

it('rate limits authenticated traffic, and unauthenticated requests do not use up the budget', function () {
    // 150 requests with a wrong secret must not lock out Telegram.
    foreach (range(1, 150) as $i) {
        postTelegram(TelegramPayload::message('x'), ['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])->assertNotFound();
    }

    foreach (range(1, 120) as $i) {
        postTelegram(TelegramPayload::message('x', from: 999888, messageId: $i))->assertOk();
    }

    postTelegram(TelegramPayload::message('x', from: 999888, messageId: 500))->assertStatus(429);
});

it('serves the webhook only over https in production', function () {
    $proxy = '10.1.2.3';

    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'TRUSTED_PROXIES' => $proxy], function () use ($proxy) {
        $secret = ['X-Telegram-Bot-Api-Secret-Token' => (string) config('telegram.secret_token')];
        $url = '/'.config('telegram.webhook_path');
        $payload = TelegramPayload::message('halo', from: 999888);

        // Plain http, no proxy: refused (404 before the secret is even looked at).
        $this->postJson('http://localhost'.$url, $payload, $secret)->assertNotFound();

        // Direct https.
        $this->postJson('https://localhost'.$url, $payload, $secret)->assertOk();

        // http behind a trusted proxy that says the original request was https.
        $this->withServerVariables(['REMOTE_ADDR' => $proxy])
            ->postJson('http://localhost'.$url, $payload, $secret + ['X-Forwarded-Proto' => 'https'])->assertOk();

        // The same header from a proxy that is NOT trusted is ignored.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson('http://localhost'.$url, $payload, $secret + ['X-Forwarded-Proto' => 'https'])->assertNotFound();
    });
});

it('does not require https outside production', function () {
    expect(app()->environment())->toBe('testing');

    postTelegram(TelegramPayload::message('halo', from: 999888))->assertOk();
});

it('refuses to boot with a wildcard in TRUSTED_PROXIES', function (string $value) {
    expect(fn () => withAppEnvironment('local', ['TRUSTED_PROXIES' => $value], fn () => null))
        ->toThrow(RuntimeException::class, 'TRUSTED_PROXIES');
})->with(['*', '10.0.0.1, *', '0.0.0.0/0', '::/0']);

it('refuses to boot in production with the fake Telegram client', function () {
    expect(fn () => withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'fake'], fn () => null))
        ->toThrow(RuntimeException::class, 'TELEGRAM_CLIENT must be "http" in production');
});

it('boots in production with the real client selected', function () {
    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http'], function () {
        expect(app()->isProduction())->toBeTrue()
            ->and(app(TelegramClient::class))->toBeInstanceOf(HttpTelegramClient::class);
    });
});

it('gives up after three failures of the same update so Telegram stops retrying', function () {
    registerTelegramUser();
    app()->bind(TelegramIngestionService::class, fn () => new class extends TelegramIngestionService
    {
        public function __construct() {}

        public function handle(TelegramUpdate $update): void
        {
            throw new RuntimeException('database is down: insert into inbound_messages values (\'jangan bocor\')');
        }
    });

    $logs = captureLogs();
    $payload = TelegramPayload::message('halo');

    postTelegram($payload)->assertStatus(500);
    postTelegram($payload)->assertStatus(500);
    postTelegram($payload)->assertOk();

    expect(loggedText($logs))->toContain('telegram.webhook_failed')->not->toContain('jangan bocor');
});

it('keeps the secure-in-production middleware a no-op outside production', function () {
    $response = (new RequireSecureInProduction)->handle(request(), fn () => response('ok'));

    expect($response->getContent())->toBe('ok');
});
