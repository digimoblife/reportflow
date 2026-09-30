<?php

use App\Services\Ai\ExtractionSchema;
use Tests\Support\Extraction;

$schema = fn () => app(ExtractionSchema::class);

it('accepts the example from PRD §54 and an empty extraction', function () use ($schema) {
    $prd = [
        'items' => [[
            'intent' => 'update_existing_task', 'project_id' => 3, 'task_ref' => ['type' => 'existing', 'task_id' => 123], 'confidence' => 0.93,
            'matching_signals' => ['same_project', 'entity:domain', 'person:Xing'],
            'activity' => ['type' => 'milestone', 'summary' => 'All premium domains configured', 'date' => '2026-09-15', 'date_precision' => 'day'],
            'status_change' => ['from' => 'in_progress', 'to' => 'completed'], 'people' => ['Xing'], 'missing_details' => [],
        ]],
        'clarification_needed' => null,
    ];

    expect($schema()->errors($prd))->toBe([])
        ->and($schema()->errors(Extraction::payload([])))->toBe([])
        ->and($schema()->errors(Extraction::payload([Extraction::newItem(3, 'Premium Domain Acquisition')])))->toBe([])
        ->and($schema()->errors(Extraction::payload([], ['question' => 'Which one?', 'options' => ['A', 'B']])))->toBe([]);
});

it('rejects malformed output', function (Closure $mutate, string $expected) use ($schema) {
    $payload = Extraction::payload([Extraction::item(1, 3, ['status_change' => ['from' => 'open', 'to' => 'completed']])]);

    expect($schema()->errors($mutate($payload)))->toContain($expected);
})->with([
    'missing items' => [fn ($p) => ['clarification_needed' => null], '$.items:required'],
    'missing clarification key' => [fn ($p) => ['items' => []], '$.clarification_needed:required'],
    'items not a list' => [fn ($p) => ['items' => 'x', 'clarification_needed' => null], '$.items:type'],
    'extra top-level key' => [fn ($p) => $p + ['debug' => true], '$.debug:additionalProperties'],
    'unknown intent' => [function ($p) { $p['items'][0]['intent'] = 'delete_task'; return $p; }, '$.items[0].intent:enum'],
    'confidence above 1' => [function ($p) { $p['items'][0]['confidence'] = 1.2; return $p; }, '$.items[0].confidence:maximum'],
    'confidence negative' => [function ($p) { $p['items'][0]['confidence'] = -0.1; return $p; }, '$.items[0].confidence:minimum'],
    'confidence as string' => [function ($p) { $p['items'][0]['confidence'] = 'high'; return $p; }, '$.items[0].confidence:type'],
    'task id as string' => [function ($p) { $p['items'][0]['task_ref']['task_id'] = '12'; return $p; }, '$.items[0].task_ref:oneOf'],
    'task id zero' => [function ($p) { $p['items'][0]['task_ref']['task_id'] = 0; return $p; }, '$.items[0].task_ref:oneOf'],
    'unknown ref type' => [function ($p) { $p['items'][0]['task_ref'] = ['type' => 'merge', 'task_id' => 2]; return $p; }, '$.items[0].task_ref:oneOf'],
    'new ref without title' => [function ($p) { $p['items'][0]['task_ref'] = ['type' => 'new']; return $p; }, '$.items[0].task_ref:oneOf'],
    'unknown activity type' => [function ($p) { $p['items'][0]['activity']['type'] = 'coding'; return $p; }, '$.items[0].activity.type:enum'],
    'bad date format' => [function ($p) { $p['items'][0]['activity']['date'] = '30/09/2026'; return $p; }, '$.items[0].activity.date:pattern'],
    'empty summary' => [function ($p) { $p['items'][0]['activity']['summary'] = ''; return $p; }, '$.items[0].activity.summary:minLength'],
    'unknown precision' => [function ($p) { $p['items'][0]['activity']['date_precision'] = 'hour'; return $p; }, '$.items[0].activity.date_precision:enum'],
    'unknown status' => [function ($p) { $p['items'][0]['status_change']['to'] = 'done'; return $p; }, '$.items[0].status_change:oneOf'],
    'status change without to' => [function ($p) { $p['items'][0]['status_change'] = ['from' => 'open']; return $p; }, '$.items[0].status_change:oneOf'],
    'people not a list' => [function ($p) { $p['items'][0]['people'] = 'Xing'; return $p; }, '$.items[0].people:type'],
    'missing field' => [function ($p) { unset($p['items'][0]['missing_details']); return $p; }, '$.items[0].missing_details:required'],
    'too many items' => [function ($p) { $p['items'] = array_fill(0, 21, $p['items'][0]); return $p; }, '$.items:maxItems'],
    'clarification without question' => [function ($p) { $p['clarification_needed'] = ['options' => ['a']]; return $p; }, '$.clarification_needed:oneOf'],
]);
