<?php

use App\Services\Ai\ExtractionSchema;
use App\Services\Worklog\CandidateBuilder;
use App\Services\Worklog\Extraction\ConfidenceLevel;
use App\Services\Worklog\Extraction\ExtractionValidator;
use App\Services\Worklog\Extraction\ItemDecision;
use Carbon\CarbonImmutable;
use Tests\Support\Extraction;

/** Validate one item against the candidate set built for `$message`; returns the ValidatedItem. */
function validateOne(array $w, array $item, string $message = 'Harbor Portal'): App\Services\Worklog\Extraction\ValidatedItem
{
    $set = app(CandidateBuilder::class)->build($message, $w['today']);
    $payload = Extraction::payload([$item]);

    expect(app(ExtractionSchema::class)->errors($payload))->toBe([], 'test payload must be schema-valid');

    return app(ExtractionValidator::class)->validate($payload, $set, $w['today'])->items[0];
}

it('accepts a clear, high-confidence update to a candidate task', function () {
    $w = worklogWorld();

    $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['people' => ['Doni']]));

    expect($item->decision)->toBe(ItemDecision::Accepted)
        ->and($item->level)->toBe(ConfidenceLevel::High)
        ->and($item->taskId)->toBe($w['tracking']->id)
        ->and($item->projectId)->toBe($w['harbor']->id)
        ->and($item->reasons)->toBe([])
        ->and($item->statusChange)->toBeNull()
        ->and($item->activityDate->format('Y-m-d'))->toBe('2026-09-30');
});

describe('task reference (steps 2-4)', function () {
    it('rejects a task id that is not in the candidate list', function () {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item(999_999, $w['harbor']->id));

        expect($item->decision)->toBe(ItemDecision::Rejected)->and($item->reasons)->toBe(['task_ref_not_in_candidates']);
    });

    it('rejects candidates that were excluded on purpose and another user\'s task', function (string $key) {
        $w = worklogWorld();
        $task = $w[$key];

        $item = validateOne($w, Extraction::item($task->id, $task->project_id));

        expect($item->decision)->toBe(ItemDecision::Rejected)->and($item->reasons)->toBe(['task_ref_not_in_candidates']);
    })->with(['cancelled', 'draft', 'oldmigration', 'strangerTask']);

    it('rejects a real task of a project the message did not name (not a candidate)', function () {
        $w = worklogWorld();

        // Message names Kedai App, so Harbor tasks are not offered and must not be accepted.
        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id), 'Kedai App');

        expect($item->decision)->toBe(ItemDecision::Rejected)->and($item->reasons)->toBe(['task_ref_not_in_candidates']);
    });

    it('rejects an intent that contradicts the reference', function () {
        $w = worklogWorld();

        $a = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['intent' => 'new_task']));
        $b = validateOne($w, Extraction::newItem($w['harbor']->id, 'X task', ['intent' => 'update_existing_task']));

        expect($a->reasons)->toBe(['intent_ref_mismatch'])->and($b->reasons)->toBe(['intent_ref_mismatch']);
    });

    it('rejects a project that does not own the task, and an unknown project', function () {
        $w = worklogWorld();

        $mismatch = validateOne($w, Extraction::item($w['tracking']->id, $w['kedai']->id));
        $unknown = validateOne($w, Extraction::newItem(424_242));
        $archived = validateOne($w, Extraction::newItem($w['archived']->id));

        expect($mismatch->reasons)->toBe(['project_task_mismatch'])
            ->and($unknown->reasons)->toBe(['project_unknown'])
            ->and($archived->reasons)->toBe(['project_unknown']);
    });

    it('infers the project of an existing task and asks when a new task has none', function () {
        $w = worklogWorld();

        $inferred = validateOne($w, Extraction::item($w['tracking']->id, null));
        $missing = validateOne($w, Extraction::newItem(null));

        expect($inferred->decision)->toBe(ItemDecision::Accepted)
            ->and($inferred->projectId)->toBe($w['harbor']->id)
            ->and($inferred->reasons)->toBe(['project_inferred_from_task'])
            ->and($missing->decision)->toBe(ItemDecision::NeedsConfirmation)
            ->and($missing->reasons)->toBe(['project_missing'])
            ->and($missing->level)->toBe(ConfidenceLevel::Medium);
    });

    it('accepts a new task in a known project', function () {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::newItem($w['harbor']->id, 'Carrier onboarding'));

        expect($item->decision)->toBe(ItemDecision::Accepted)->and($item->isNewTask())->toBeTrue()->and($item->taskId)->toBeNull();
    });
});

describe('status (step 5)', function () {
    it('keeps a valid transition and marks terminal statuses explicit', function (string $taskKey, string $to, bool $terminal) {
        $w = worklogWorld();
        $task = $w[$taskKey];

        $item = validateOne($w, Extraction::item($task->id, $task->project_id, ['status_change' => ['from' => $task->status->value, 'to' => $to]]));

        expect($item->statusChange)->toBe(['from' => $task->status->value, 'to' => $to])
            ->and($item->explicitTerminal)->toBe($terminal)
            ->and($item->decision)->toBe(ItemDecision::Accepted);
    })->with([
        'in_progress -> completed' => ['tracking', 'completed', true],
        'in_progress -> waiting' => ['tracking', 'waiting', false],
        'open -> in_progress' => ['invoice', 'in_progress', false],
        'open -> cancelled' => ['invoice', 'cancelled', true],
        'completed -> in_progress (reopen)' => ['dock', 'in_progress', false],
        'waiting -> blocked' => ['sso', 'blocked', false],
    ]);

    it('uses the real current status, not the one the model claims', function () {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['status_change' => ['from' => 'open', 'to' => 'completed']]));

        expect($item->statusChange)->toBe(['from' => 'in_progress', 'to' => 'completed'])
            ->and($item->reasons)->toBe(['status_from_mismatch'])
            ->and($item->decision)->toBe(ItemDecision::NeedsConfirmation);
    });

    it('drops a same-status change as a no-op', function () {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['status_change' => ['from' => 'in_progress', 'to' => 'in_progress']]));

        expect($item->statusChange)->toBeNull()->and($item->reasons)->toBe(['status_unchanged'])->and($item->decision)->toBe(ItemDecision::Accepted);
    });

    it('refuses transitions the matrix forbids and asks instead', function (string $taskKey, string $to) {
        $w = worklogWorld();
        $task = $w[$taskKey];

        $item = validateOne($w, Extraction::item($task->id, $task->project_id, ['status_change' => ['from' => $task->status->value, 'to' => $to]]));

        expect($item->statusChange)->toBeNull()
            ->and($item->reasons)->toBe(['status_transition_invalid'])
            ->and($item->decision)->toBe(ItemDecision::NeedsConfirmation)
            ->and($item->level)->toBe(ConfidenceLevel::Medium);
    })->with([
        'completed -> waiting' => ['dock', 'waiting'],
        'completed -> cancelled' => ['dock', 'cancelled'],
        'completed -> open' => ['dock', 'open'],
        'in_progress -> open' => ['tracking', 'open'],
        'open -> draft' => ['invoice', 'draft'],
    ]);

    it('lets a new task start in a sensible status only', function () {
        $w = worklogWorld();

        $ok = validateOne($w, Extraction::newItem($w['harbor']->id, 'Quick fix', ['status_change' => ['from' => null, 'to' => 'completed']]));
        $bad = validateOne($w, Extraction::newItem($w['harbor']->id, 'Odd one', ['status_change' => ['from' => null, 'to' => 'cancelled']]));

        expect($ok->statusChange)->toBe(['from' => null, 'to' => 'completed'])
            ->and($bad->statusChange)->toBeNull()
            ->and($bad->reasons)->toBe(['status_initial_invalid'])
            ->and($bad->decision)->toBe(ItemDecision::NeedsConfirmation);
    });
});

describe('date (step 6)', function () {
    it('accepts today and any date inside the window, including exactly 30 days ago', function (string $date) {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => $date]]));

        expect($item->decision)->toBe(ItemDecision::Accepted)->and($item->reasons)->toBe([]);
    })->with(['2026-09-30', '2026-09-29', '2026-09-01']);

    it('asks for confirmation when the date is more than 30 days back', function () {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => '2026-08-30']]));

        expect($item->decision)->toBe(ItemDecision::NeedsConfirmation)->and($item->reasons)->toBe(['date_older_than_30_days'])->and($item->level)->toBe(ConfidenceLevel::Medium);
    });

    it('rejects future and impossible dates', function (string $date, string $reason) {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => $date]]));

        expect($item->decision)->toBe(ItemDecision::Rejected)->and($item->reasons)->toBe([$reason]);
    })->with([
        'tomorrow' => ['2026-10-01', 'date_in_future'],
        'far future' => ['2027-01-01', 'date_in_future'],
        'feb 30' => ['2026-02-30', 'date_invalid'],
        'month 13' => ['2026-13-01', 'date_invalid'],
    ]);

    it('judges "today" in the user timezone', function () {
        $w = worklogWorld();
        // 2026-09-30 23:30 in Jakarta is still the 30th there although UTC has not reached it in some runs.
        $today = CarbonImmutable::parse('2026-09-30 23:30', 'Asia/Jakarta')->startOfDay();
        $set = app(CandidateBuilder::class)->build('Harbor Portal', $today);

        $result = app(ExtractionValidator::class)->validate(Extraction::payload([Extraction::item($w['tracking']->id, $w['harbor']->id, ['activity' => ['date' => '2026-09-30']])]), $set, $today);

        expect($result->items[0]->decision)->toBe(ItemDecision::Accepted);
    });
});

describe('confidence (step 7)', function () {
    it('maps the model confidence to accept / confirm', function (float $confidence, ItemDecision $decision, ConfidenceLevel $level) {
        $w = worklogWorld();

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => $confidence]));

        expect($item->decision)->toBe($decision)->and($item->level)->toBe($level);
    })->with([
        [0.99, ItemDecision::Accepted, ConfidenceLevel::High],
        [0.90, ItemDecision::Accepted, ConfidenceLevel::High],
        [0.89, ItemDecision::NeedsConfirmation, ConfidenceLevel::Medium],
        [0.70, ItemDecision::NeedsConfirmation, ConfidenceLevel::Medium],
        [0.69, ItemDecision::NeedsConfirmation, ConfidenceLevel::Low],
        [0.10, ItemDecision::NeedsConfirmation, ConfidenceLevel::Low],
    ]);

    it('downgrades high confidence when deterministic signals disagree', function () {
        $w = worklogWorld();

        $stranger = validateOne($w, Extraction::item($w['invoice']->id, $w['harbor']->id, ['people' => ['Zed']]));
        $stale = validateOne($w, Extraction::item($w['sso']->id, $w['harbor']->id));
        \App\Models\Task::query()->whereKey($w['sso']->id)->update(['last_activity_at' => '2026-03-01 00:00:00+00']);
        $staleNow = validateOne($w, Extraction::item($w['sso']->id, $w['harbor']->id));

        expect($stranger->decision)->toBe(ItemDecision::NeedsConfirmation)->and($stranger->reasons)->toBe(['person_mismatch'])
            ->and($stale->decision)->toBe(ItemDecision::Accepted)
            ->and($staleNow->decision)->toBe(ItemDecision::NeedsConfirmation)->and($staleNow->reasons)->toBe(['stale_task']);
    });

    it('honours thresholds from config', function () {
        $w = worklogWorld();
        config(['ai.confidence.high' => 0.99]);
        app()->forgetInstance(App\Services\Worklog\Extraction\ConfidencePolicy::class);
        app()->forgetInstance(ExtractionValidator::class);

        $item = validateOne($w, Extraction::item($w['tracking']->id, $w['harbor']->id, ['confidence' => 0.95]));

        expect($item->level)->toBe(ConfidenceLevel::Medium);
    });
});

it('validates every item independently and passes the clarification through', function () {
    $w = worklogWorld();
    $set = app(CandidateBuilder::class)->build('Harbor Portal', $w['today']);

    $proposal = app(ExtractionValidator::class)->validate(Extraction::payload([
        Extraction::item($w['tracking']->id, $w['harbor']->id),
        Extraction::item(999_999, $w['harbor']->id),
        Extraction::newItem($w['harbor']->id, 'Third item', ['confidence' => 0.5]),
    ], ['question' => 'Which export?', 'options' => ['Invoice', 'Appointments']]), $set, $w['today']);

    expect(array_map(fn ($i) => $i->decision, $proposal->items))->toBe([ItemDecision::Accepted, ItemDecision::Rejected, ItemDecision::NeedsConfirmation])
        ->and(array_map(fn ($i) => $i->index, $proposal->items))->toBe([0, 1, 2])
        ->and($proposal->summary())->toBe(['accepted' => 1, 'rejected' => 1, 'needs_confirmation' => 1])
        ->and($proposal->withDecision(ItemDecision::Rejected))->toHaveCount(1)
        ->and($proposal->clarification['question'])->toBe('Which export?')
        ->and($proposal->isEmpty())->toBeFalse();
});

it('returns an empty proposal for a note with no work in it', function () {
    $w = worklogWorld();
    $set = app(CandidateBuilder::class)->build('halo', $w['today']);

    $proposal = app(ExtractionValidator::class)->validate(Extraction::payload([]), $set, $w['today']);

    expect($proposal->isEmpty())->toBeTrue()->and($proposal->summary())->toBe([]);
});
