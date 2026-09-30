<?php

use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\DeepSeekProvider;
use App\Services\Ai\Eval\EvalDataset;
use App\Services\Ai\Eval\EvalRunner;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Ai\PrefetchingProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function req(string $user): AiRequest
{
    return new AiRequest('worklog_extraction', 'worklog_extraction@v1', 'SYSTEM json', $user);
}

it('answers a prefetched request once, then falls back to the real provider', function () {
    $fake = (new FakeAiProvider)->queue('first', 'second', 'live');
    $prefetch = new PrefetchingProvider($fake);

    $prefetch->prefetch([req('a'), req('b')], 4);

    expect($fake->batchCalls)->toBe(1)
        ->and($prefetch->complete(req('b'))->content)->toBe('second')       // by identity, not by order
        ->and($prefetch->complete(req('a'))->content)->toBe('first')
        ->and($prefetch->complete(req('a'))->content)->toBe('live')          // second ask = a retry: real provider
        ->and(count($fake->requests))->toBe(3);
});

it('replays a prefetched failure as an exception, once', function () {
    $fake = (new FakeAiProvider)->failWith(new AiProviderException('down'), times: 1);
    $prefetch = new PrefetchingProvider($fake);

    $prefetch->prefetch([req('x')], 2);

    expect(fn () => $prefetch->complete(req('x')))->toThrow(AiProviderException::class, 'down')
        ->and($prefetch->complete(req('x'))->content)->toBe(FakeAiProvider::EMPTY_EXTRACTION);
});

describe('DeepSeekProvider::completeMany', function () {
    beforeEach(function () {
        config(['ai.deepseek.api_key' => 'k'.str_repeat('e', 20), 'ai.deepseek.base_url' => 'https://api.deepseek.test']);
    });

    it('sends the requests concurrently and returns results in order', function () {
        Http::fake(fn (Request $r) => Http::response(['model' => 'deepseek-flash', 'choices' => [['message' => ['content' => 'reply to '.$r['messages'][1]['content']]]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]]));

        $results = (new DeepSeekProvider)->completeMany([req('one'), req('two'), req('three')], 2);

        expect(array_map(fn ($r) => $r->content, $results))->toBe(['reply to one', 'reply to two', 'reply to three'])
            ->and($results[0]->tokensInput)->toBe(5);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.config('ai.deepseek.api_key')) && $r['response_format'] === ['type' => 'json_object']);
    });

    it('returns per-request failures without throwing and without leaking the key', function () {
        $key = config('ai.deepseek.api_key');
        Http::fake(function (Request $r) use ($key) {
            return match ($r['messages'][1]['content']) {
                'ok' => Http::response(['choices' => [['message' => ['content' => 'fine']]]]),
                'bad' => Http::response(['error' => 'x '.$key], 500),
                default => throw new ConnectionException('timed out for https://api.deepseek.test with Bearer '.$key),
            };
        });

        $results = (new DeepSeekProvider)->completeMany([req('ok'), req('bad'), req('boom')], 3);

        expect($results[0]->content)->toBe('fine')
            ->and($results[1])->toBeInstanceOf(AiProviderException::class)->and($results[1]->getMessage())->toBe('DeepSeek request failed with HTTP 500.')
            ->and($results[2])->toBeInstanceOf(AiProviderException::class)
            ->and(json_encode([$results[1]->getMessage(), $results[2]->getMessage(), (string) $results[2]]))->not->toContain($key);
    });

    it('refuses without an API key', function () {
        config(['ai.deepseek.api_key' => '']);

        expect(fn () => (new DeepSeekProvider)->completeMany([req('x')], 2))->toThrow(AiProviderException::class, 'not configured');
    });
});

describe('eval runner concurrency and filters', function () {
    it('gives the same result with prefetching as without', function () {
        $dataset = EvalDataset::load(base_path('tests/Eval/data-sample'), 'sample');
        $sequential = app(EvalRunner::class)->run($dataset, new FakeAiProvider, 'worklog_extraction@v1');
        $fake = new FakeAiProvider;

        $concurrent = app(EvalRunner::class)->run($dataset, $fake, 'worklog_extraction@v1', null, 8);

        expect($fake->batchCalls)->toBe(1)
            ->and(count($fake->requests))->toBe(count($dataset->cases))     // each request made exactly once
            ->and($concurrent->metrics())->toBe($sequential->metrics())
            ->and(array_column($concurrent->cases, 'scores'))->toBe(array_column($sequential->cases, 'scores'));
    });

    it('retries an invalid prefetched reply live, once', function () {
        $dataset = EvalDataset::load(base_path('tests/Eval/data-sample'), 'sample')->filter(ids: ['C001']);
        $fake = (new FakeAiProvider)->queue('not json');   // prefetched answer is invalid; the retry gets the default (valid, empty)

        $report = app(EvalRunner::class)->run($dataset, $fake, 'worklog_extraction@v1', null, 4);

        expect(count($fake->requests))->toBe(2)->and($report->problemIds()['failed'])->toBe([]);
    });

    it('filters by category, ids and limit', function () {
        $dataset = EvalDataset::load(base_path('tests/Eval/data-sample'), 'sample');

        expect(count($dataset->filter(categories: ['future'])->cases))->toBe(2)
            ->and(array_column($dataset->filter(ids: ['C003', 'C005'])->cases, 'id'))->toBe(['C003', 'C005'])
            ->and(count($dataset->filter(categories: ['basic', 'status'], limit: 4)->cases))->toBe(4)
            ->and($dataset->filter(categories: ['nope'])->cases)->toBe([]);
    });
});
