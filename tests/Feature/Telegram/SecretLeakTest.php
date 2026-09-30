<?php

use App\Enums\InboundMessageStatus;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Redaction\RedactionService;
use App\Services\Telegram\TelegramUpdate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\TestHandler;
use Tests\Support\FakeSecrets;
use Tests\Support\TelegramPayload;

/*
| Canary tests: a fake secret sent through the whole pipeline must not appear ANYWHERE:
| database (every table), logs, queue payloads, the AI provider, or outgoing Telegram messages.
*/

beforeEach(function () {
    config(['queue.default' => 'database']);
    registerTelegramUser(555001);
});

function everywhereText(?TestHandler $logs = null): array
{
    $tables = collect(DB::select("select tablename from pg_tables where schemaname = 'public'"))->pluck('tablename');
    $dump = [];

    foreach ($tables as $table) {
        $dump[] = json_encode(DB::table($table)->get());
    }

    return [
        'database' => implode("\n", $dump),
        'logs' => $logs ? loggedText($logs) : '',
        'ai' => json_encode(array_map(fn (AiRequest $r) => [$r->purpose, $r->promptVersion, $r->input], fakeAi()->requests)),
        'telegram' => implode("\n", fakeTelegram()->allTexts()),
    ];
}

function work(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0, '--queue' => 'default', '--memory' => 4096]);
}

it('does not leak credentials anywhere on the happy path', function () {
    $secrets = [FakeSecrets::canary(), FakeSecrets::githubToken(), FakeSecrets::password(), FakeSecrets::dbUri(), FakeSecrets::telegramBotToken()];
    $logs = captureLogs();

    postTelegram(TelegramPayload::message(
        'Deploy selesai. key '.$secrets[0].' token '.$secrets[1].' password: '.$secrets[2].' db '.$secrets[3].' bot '.$secrets[4]
    ))->assertOk();
    work();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Processed)
        ->and(storedMessages()->sole()->text)->toBe('Deploy selesai. key [REDACTED_SECRET] token [REDACTED_SECRET] password: [REDACTED_SECRET] db [REDACTED_SECRET] bot [REDACTED_SECRET]');

    foreach (everywhereText($logs) as $place => $text) {
        foreach ($secrets as $secret) {
            expect($text)->not->toContain($secret, "secret found in $place");
        }
    }

    // The AI provider saw the redacted text only.
    expect(fakeAi()->requests[0]->input)->toContain('[REDACTED_SECRET]');
});

it('does not leak credentials on the failure path, in an edit, or via exceptions', function () {
    $canary = FakeSecrets::canary();
    $logs = captureLogs();
    fakeAi()->failWith(new AiProviderException("provider said no to $canary"));

    postTelegram(TelegramPayload::message("catatan dengan $canary", messageId: 1))->assertOk();
    postTelegram(TelegramPayload::edited("suntingan dengan $canary lagi", messageId: 1))->assertOk();
    foreach ([0, 6, 40, 200] as $secondsLater) {
        Carbon::setTestNow(now()->addSeconds($secondsLater));
        work();
    }
    Carbon::setTestNow();

    expect(storedMessages()->sole()->status)->toBe(InboundMessageStatus::Failed);

    foreach (everywhereText($logs) as $place => $text) {
        expect($text)->not->toContain($canary, "canary found in $place");
    }
});

it('keeps raw text out of the update object once it is redacted', function () {
    $canary = FakeSecrets::canary();
    $update = TelegramUpdate::fromArray(TelegramPayload::message("rahasia $canary"));

    expect(print_r($update, true))->not->toContain($canary)
        ->and(json_encode($update))->not->toContain($canary)
        ->and(fn () => serialize($update))->toThrow(LogicException::class);

    ob_start();
    var_dump($update);
    expect(ob_get_clean())->not->toContain($canary);
});

it('marks every parameter that receives raw text as sensitive', function (string $class, string $method, string $parameter) {
    $reflection = (new ReflectionMethod($class, $method))->getParameters();
    $param = collect($reflection)->firstWhere(fn (ReflectionParameter $p) => $p->getName() === $parameter);

    expect($param)->not->toBeNull()
        ->and($param->getAttributes(SensitiveParameter::class))->not->toBeEmpty("$class::$method($parameter)");
})->with([
    [TelegramUpdate::class, 'fromArray', 'payload'],
    [RedactionService::class, 'redact', 'text'],
    [AiRequest::class, '__construct', 'input'],
    [TelegramUpdate::class, '__construct', 'text'],
    [TelegramUpdate::class, '__construct', 'caption'],
]);

it('hides argument values in exception traces when a raw-text function throws', function () {
    $canary = FakeSecrets::canary();

    $failing = function (#[SensitiveParameter] string $text) {
        throw new RuntimeException('failed');
    };

    try {
        $failing("raw $canary");
    } catch (RuntimeException $e) {
        // With zend.exception_ignore_args=On (docker/php/php.ini) no args are recorded at all; with it Off,
        // the attribute replaces the value by SensitiveParameterValue. Either way the text must not appear.
        expect($e->getTraceAsString())->not->toContain($canary);
    }
});
