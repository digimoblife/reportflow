<?php

use App\Services\Redaction\RedactingLogProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\Support\FakeSecrets;

function logRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'test', Level::Error, $message, $context, $extra);
}

it('redacts secrets in the message, context and extra, at any depth', function () {
    $secret = FakeSecrets::githubToken();
    $processor = new RedactingLogProcessor;

    $record = $processor(logRecord("token $secret leaked", ['a' => ['b' => ['c' => 'password: '.FakeSecrets::password()]], 'n' => 5, 'ok' => true], ['x' => $secret]));

    expect(json_encode($record->toArray()))->not->toContain($secret)->not->toContain(FakeSecrets::password())
        ->and($record->message)->toBe('token [REDACTED_SECRET] leaked')
        ->and($record->context['n'])->toBe(5)
        ->and($record->context['ok'])->toBeTrue();
});

it('flattens throwables: message and trace are redacted, the object is gone', function () {
    $secret = FakeSecrets::openAiKey();
    $processor = new RedactingLogProcessor;

    $throw = function (string $arg) {
        throw new RuntimeException("failed with $arg");
    };

    try {
        $throw($secret);
    } catch (RuntimeException $e) {
        $record = $processor(logRecord('boom', ['exception' => $e]));
    }

    expect($record->context['exception'])->toBeArray()
        ->and($record->context['exception']['class'])->toBe(RuntimeException::class)
        ->and(json_encode($record->context))->not->toContain($secret)
        ->and($record->context['exception']['message'])->toBe('failed with [REDACTED_SECRET]');
});

it('reduces objects to their class name', function () {
    $object = new class
    {
        public string $text = 'jangan tampilkan';
    };

    $record = (new RedactingLogProcessor)(logRecord('x', ['o' => $object]));

    expect(json_encode($record->context))->not->toContain('jangan tampilkan');
});

it('withholds the whole record instead of failing open', function () {
    $record = (new RedactingLogProcessor)(logRecord('x', ['deep' => str_repeat('a', 2_000_000)]));

    // Over-long input fails closed to the placeholder rather than passing through.
    expect($record->context['deep'])->toBe('[REDACTED_SECRET]');
});
