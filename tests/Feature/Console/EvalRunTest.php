<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

function evalOutput(array $options = []): array
{
    $code = Artisan::call('eval:run', $options);

    return [$code, Artisan::output()];
}

it('runs the sample dataset with the oracle and prints numbers and case ids only', function () {
    [$code, $out] = evalOutput();

    expect($code)->toBe(0)
        ->and($out)->toContain('Prompt worklog_extraction@v2 | provider fake | dataset sample | 66 cases')
        ->and($out)->toContain('Worklog extraction')->toContain('Project identification')->toContain('Task matching')->toContain('Date extraction')
        ->and($out)->toContain('100.0%')->toContain('Pipeline failures: none')->toContain('Labels flagged for human review: C004')
        ->and($out)->toContain('oracle')
        ->and($out)->not->toContain('Harbor:')->not->toContain('Kedai Senja:');   // no message text
});

it('runs the realistic dataset', function () {
    [$code, $out] = evalOutput(['--dataset' => 'realistic']);

    expect($code)->toBe(0)->and($out)->toContain('dataset realistic | 107 cases')->toContain('Pipeline failures: none');
});

it('prints a JSON report and writes it to a file, then compares with it as a baseline', function () {
    $file = sys_get_temp_dir().'/eval-report-'.uniqid().'.json';

    [$code, $out] = evalOutput(['--json' => true, '--out' => $file]);
    $json = json_decode($out, true);

    expect($code)->toBe(0)
        ->and($json['case_count'])->toBe(66)
        ->and($json['metrics']['matching']['rate'])->toEqual(1)
        ->and(json_decode((string) file_get_contents($file), true)['prompt'])->toBe('worklog_extraction@v2');

    [, $compared] = evalOutput(['--baseline' => $file]);
    expect($compared)->toContain('Improved vs baseline: none')->toContain('Regressed vs baseline: none');

    unlink($file);
});

it('refuses the real provider unless you confirm that messages will be sent', function () {
    config(['ai.deepseek.api_key' => 'x'.'y'.str_repeat('z', 20)]);
    Http::fake();

    [$code, $out] = evalOutput(['--provider' => 'deepseek']);

    expect($code)->toBe(1)->and($out)->toContain('--send-to-deepseek');
    Http::assertNothingSent();
});

it('refuses the real provider without an API key', function () {
    config(['ai.deepseek.api_key' => '']);
    Http::fake();

    [$code, $out] = evalOutput(['--provider' => 'deepseek', '--send-to-deepseek' => true]);

    expect($code)->toBe(1)->and($out)->toContain('DEEPSEEK_API_KEY');
    Http::assertNothingSent();
});

it('calls DeepSeek once per case when confirmed, sending redacted text only', function () {
    config(['ai.deepseek.api_key' => 'k'.str_repeat('e', 20), 'ai.deepseek.base_url' => 'https://api.deepseek.test']);
    Http::fake(['api.deepseek.test/*' => Http::response(['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => '{"items":[],"clarification_needed":null}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]])]);

    [$code, $out] = evalOutput(['--provider' => 'deepseek', '--send-to-deepseek' => true]);

    expect($code)->toBe(0)
        ->and($out)->toContain('provider deepseek')->toContain('Usage: 66 AI calls, 660 input / 132 output tokens')
        ->and($out)->not->toContain('oracle');

    Http::assertSentCount(66);
    Http::assertSent(fn (Request $r) => ! str_contains(json_encode($r->data()), 'EVALFAKE') && ! str_contains(json_encode($r->data()), '{{secret'));
});

it('runs only the selected cases', function () {
    [$code, $out] = evalOutput(['--only' => 'future,chitchat']);

    expect($code)->toBe(0)->and($out)->toContain('5 cases');

    [, $ids] = evalOutput(['--ids' => 'C001,C002,C003', '--limit' => 2]);
    expect($ids)->toContain('2 cases');
});

it('uses several concurrent requests to DeepSeek when asked to', function () {
    config(['ai.deepseek.api_key' => 'k'.str_repeat('e', 20), 'ai.deepseek.base_url' => 'https://api.deepseek.test']);
    Http::fake(['api.deepseek.test/*' => Http::response(['choices' => [['message' => ['content' => '{"items":[],"clarification_needed":null}']]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2]])]);

    [$code, $out] = evalOutput(['--provider' => 'deepseek', '--send-to-deepseek' => true, '--only' => 'chitchat', '--concurrency' => 4]);

    expect($code)->toBe(0)->and($out)->toContain('3 cases');
    Http::assertSentCount(3);
});

it('validates its options', function (array $options, string $message) {
    [$code, $out] = evalOutput($options);

    expect($code)->toBe(1)->and($out)->toContain($message);
})->with([
    'unknown dataset' => [['--dataset' => 'prod'], '--dataset must be'],
    'unknown provider' => [['--provider' => 'gpt'], '--provider must be'],
    'unknown prompt version' => [['--prompt' => 'worklog_extraction@v9'], 'does not exist'],
    'missing local dataset' => [['--dataset' => 'local'], 'not found'],
    'too much concurrency' => [['--concurrency' => 99], '--concurrency must be'],
    'no matching case' => [['--only' => 'nothing-like-this'], 'No dataset case matches'],
]);

it('is refused in production', function () {
    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'PDF_RENDERER' => 'gotenberg'], function () {
        expect(Artisan::call('eval:run'))->toBe(1)
            ->and(Artisan::output())->toContain('not allowed in production');
    });
});
