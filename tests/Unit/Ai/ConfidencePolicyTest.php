<?php

use App\Services\Worklog\Extraction\ConfidenceLevel;
use App\Services\Worklog\Extraction\ConfidencePolicy;
use Carbon\CarbonImmutable;

it('maps confidence to levels at the PRD §13 boundaries', function (float $confidence, ConfidenceLevel $level) {
    expect((new ConfidencePolicy)->level($confidence))->toBe($level);
})->with([
    [1.0, ConfidenceLevel::High], [0.90, ConfidenceLevel::High], [0.8999, ConfidenceLevel::Medium],
    [0.89, ConfidenceLevel::Medium], [0.70, ConfidenceLevel::Medium], [0.6999, ConfidenceLevel::Low],
    [0.0, ConfidenceLevel::Low],
]);

it('uses configurable thresholds', function () {
    $policy = new ConfidencePolicy(high: 0.95, medium: 0.60);

    expect($policy->level(0.94))->toBe(ConfidenceLevel::Medium)->and($policy->level(0.60))->toBe(ConfidenceLevel::Medium)->and($policy->level(0.59))->toBe(ConfidenceLevel::Low);
});

it('downgrades only High, and only when a signal contradicts', function () {
    $policy = new ConfidencePolicy;

    expect($policy->combine(ConfidenceLevel::High, ['stale_task']))->toBe(ConfidenceLevel::Medium)
        ->and($policy->combine(ConfidenceLevel::High, []))->toBe(ConfidenceLevel::High)
        ->and($policy->combine(ConfidenceLevel::Medium, ['stale_task']))->toBe(ConfidenceLevel::Medium)
        ->and($policy->combine(ConfidenceLevel::Low, ['stale_task']))->toBe(ConfidenceLevel::Low);
});

function taskView(array $overrides = []): array
{
    return $overrides + ['status' => 'in_progress', 'last_activity_at' => '2026-09-25T00:00:00+00:00', 'people' => ['Rina'], 'recent_activities' => [['date' => '2026-09-25', 'type' => 'request', 'summary' => 'Doni built the webhook receiver']]];
}

it('detects contradicting deterministic signals', function (array $task, array $people, array $expected) {
    expect((new ConfidencePolicy)->contradictions($task, $people, CarbonImmutable::parse('2026-09-30')))->toBe($expected);
})->with([
    'clean' => [taskView(), ['Rina'], []],
    'no people mentioned' => [taskView(), [], []],
    'cancelled task' => [taskView(['status' => 'cancelled']), [], ['task_cancelled']],
    'stale active task' => [taskView(['last_activity_at' => '2026-05-01T00:00:00+00:00']), [], ['stale_task']],
    'stale but completed is fine' => [taskView(['status' => 'completed', 'last_activity_at' => '2026-05-01T00:00:00+00:00']), [], []],
    'person unknown to the task' => [taskView(), ['Zed'], ['person_mismatch']],
    'person known from activity history' => [taskView(), ['doni'], []],
    'task without people cannot mismatch' => [taskView(['people' => []]), ['Zed'], []],
    'case-insensitive person' => [taskView(), ['rina'], []],
]);
