<?php

use App\Models\AiInteraction;
use App\Services\Ai\AiExtractionFailed;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AIService;
use App\Services\Ai\ExtractionOutcome;
use App\Services\Redaction\RedactionService;
use App\Services\Worklog\CandidateBuilder;
use Tests\Support\Extraction;
use Tests\Support\FakeSecrets;

function runExtraction(array $w, string $message = 'Harbor Portal: webhook sudah jalan', ?int $inboundId = null): ExtractionOutcome
{
    $set = app(CandidateBuilder::class)->build($message, $w['today']);

    return app(AIService::class)->extractWorklog($message, $set, $w['today'], $inboundId);
}

it('returns the parsed extraction and logs one interaction with tokens, latency and prompt version', function () {
    $w = worklogWorld();
    fakeAi()->respondWith(Extraction::json([Extraction::item($w['tracking']->id, $w['harbor']->id)]));

    $outcome = runExtraction($w);

    expect($outcome->attempts)->toBe(1)
        ->and($outcome->promptVersion)->toBe('worklog_extraction@v1')
        ->and($outcome->data['items'])->toHaveCount(1)
        ->and($outcome->tokensInput)->toBe(100)->and($outcome->tokensOutput)->toBe(50);

    $row = AiInteraction::query()->sole();
    expect($row->purpose)->toBe('worklog_extraction')
        ->and($row->model)->toBe('fake-model')
        ->and($row->prompt_version)->toBe('worklog_extraction@v1')
        ->and($row->success)->toBeTrue()
        ->and($row->error)->toBeNull()
        ->and($row->tokens_input)->toBe(100)->and($row->tokens_output)->toBe(50)->and($row->latency_ms)->toBe(1)
        ->and($row->project_id)->toBe($w['harbor']->id)
        ->and($row->user_id)->toBe($w['user']->id)
        ->and($row->input['message'])->toBe('Harbor Portal: webhook sudah jalan')
        ->and($row->input['today'])->toBe('2026-09-30')
        ->and(array_column($row->input['candidates'], 'id'))->toContain($w['tracking']->id)
        ->and($row->input)->not->toHaveKey('system');
});

it('sends the versioned prompt as system message and the candidates as JSON in the user message', function () {
    $w = worklogWorld();

    runExtraction($w);

    $request = fakeAi()->requests[0];
    $user = json_decode($request->user, true);

    expect($request->purpose)->toBe('worklog_extraction')
        ->and($request->promptVersion)->toBe('worklog_extraction@v1')
        ->and($request->jsonMode)->toBeTrue()
        ->and($request->system)->toBe(file_get_contents(resource_path('prompts/worklog_extraction/v1.md')))
        ->and($user)->toHaveKeys(['today', 'timezone', 'message', 'projects', 'candidates_mode', 'candidates'])
        ->and($user['timezone'])->toBe('Asia/Jakarta')
        ->and($user['candidates_mode'])->toBe('focused')
        ->and($user)->not->toHaveKey('previous_reply_rejected');
});

it('accepts JSON wrapped in a code fence', function () {
    $w = worklogWorld();
    fakeAi()->respondWith("```json\n".Extraction::json([])."\n```");

    expect(runExtraction($w)->data['items'])->toBe([]);
});

it('retries once when the reply is not valid, telling the model which fields failed', function () {
    $w = worklogWorld();
    $bad = Extraction::payload([Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 7])]);
    fakeAi()->queue(json_encode($bad), Extraction::json([Extraction::item($w['tracking']->id, $w['harbor']->id)]));

    $outcome = runExtraction($w);

    expect($outcome->attempts)->toBe(2)
        ->and($outcome->tokensInput)->toBe(200)
        ->and(fakeAi()->requests)->toHaveCount(2)
        ->and(json_decode(fakeAi()->requests[1]->user, true)['previous_reply_rejected'])->toBe(['$.items[0].confidence:maximum']);

    $rows = AiInteraction::query()->orderBy('id')->get();
    expect($rows->pluck('success')->all())->toBe([false, true])
        ->and($rows[0]->error)->toBe('$.items[0].confidence:maximum');
});

it('retries once on invalid JSON and on an empty reply', function (string $first, string $code) {
    $w = worklogWorld();
    fakeAi()->queue($first, Extraction::json([]));

    $outcome = runExtraction($w);

    expect($outcome->attempts)->toBe(2)->and(AiInteraction::query()->orderBy('id')->first()->error)->toBe($code);
})->with([
    'prose' => ['Sorry, I cannot do that', 'invalid_json'],
    'truncated json' => ['{"items":[{"intent":', 'invalid_json'],
    'empty' => ['', 'empty_reply'],
]);

it('gives up after the retry with the codes only', function () {
    $w = worklogWorld();
    fakeAi()->queue('not json', '{"items":"nope","clarification_needed":null}');

    try {
        runExtraction($w);
        $this->fail('expected AiExtractionFailed');
    } catch (AiExtractionFailed $e) {
        expect($e->codes)->toBe(['$.items:type'])->and($e->getMessage())->toBe('AI extraction failed validation: $.items:type');
    }

    expect(fakeAi()->requests)->toHaveCount(2)
        ->and(AiInteraction::query()->pluck('success')->all())->toBe([false, false]);
});

it('does not retry provider errors: it logs them and lets the queue retry', function () {
    $w = worklogWorld();
    fakeAi()->failWith(new AiProviderException('timeout'));

    expect(fn () => runExtraction($w))->toThrow(AiProviderException::class);

    expect(fakeAi()->requests)->toHaveCount(1);
    $row = AiInteraction::query()->sole();
    expect($row->success)->toBeFalse()->and($row->error)->toBe('provider_error')->and($row->output)->toBeNull();
});

it('uses the prompt version it is given, and rejects one that does not exist', function () {
    $w = worklogWorld();
    $set = app(CandidateBuilder::class)->build('x', $w['today']);

    $outcome = app(AIService::class)->extractWorklog('x', $set, $w['today'], null, 'worklog_extraction@v2');

    expect($outcome->promptVersion)->toBe('worklog_extraction@v2')
        ->and(fakeAi()->requests[0]->promptVersion)->toBe('worklog_extraction@v2')
        ->and(fakeAi()->requests[0]->system)->toBe(file_get_contents(resource_path('prompts/worklog_extraction/v2.md')))
        ->and(AiInteraction::query()->sole()->prompt_version)->toBe('worklog_extraction@v2');

    expect(fn () => app(AIService::class)->extractWorklog('x', $set, $w['today'], null, 'worklog_extraction@v9'))->toThrow(InvalidArgumentException::class);
});

it('keeps secrets out of everything it sends and stores, given redacted input', function () {
    $w = worklogWorld();
    $canary = FakeSecrets::canary();
    $message = app(RedactionService::class)->redact("Harbor Portal deploy pakai key $canary")->text;

    runExtraction($w, $message);

    $row = AiInteraction::query()->sole();
    expect(fakeAi()->requests[0]->user)->not->toContain($canary)
        ->and(json_encode($row->toArray()))->not->toContain($canary)
        ->and($row->input['message'])->toBe('Harbor Portal deploy pakai key [REDACTED_SECRET]');
});
