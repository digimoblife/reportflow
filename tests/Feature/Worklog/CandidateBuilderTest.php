<?php

use App\Services\Worklog\CandidateBuilder;
use Carbon\CarbonImmutable;

function candidateTitles(App\Services\Worklog\CandidateSet $set): array
{
    $titles = array_column($set->tasks, 'title');
    sort($titles);

    return $titles;
}

it('focuses on the project named in the message: active tasks plus recently completed ones', function () {
    $w = worklogWorld();

    $set = app(CandidateBuilder::class)->build('Hari ini update Harbor Portal soal tracking', $w['today']);

    expect($set->isCompact())->toBeFalse()
        ->and($set->detectedProjectIds)->toBe([$w['harbor']->id])
        ->and(candidateTitles($set))->toBe([
            'Customer SSO Login', 'Dock Sensor Feed', 'Dock Utilization Report', 'Invoice PDF Export Bug', 'Shipment Tracking API',
        ]);
});

it('excludes draft, cancelled, deleted, old completed tasks, archived projects and other users', function () {
    $w = worklogWorld();
    $set = app(CandidateBuilder::class)->build('harbor portal', $w['today']);

    foreach (['draft', 'cancelled', 'oldmigration'] as $key) {
        expect($set->hasTask($w[$key]->id))->toBeFalse($key);
    }
    expect(candidateTitles($set))->not->toContain('Deleted Task')->not->toContain('Stranger Task')
        ->and($set->hasProject($w['archived']->id))->toBeFalse()
        ->and($set->hasTask($w['strangerTask']->id))->toBeFalse();
});

it('detects projects by alias, whole word, case-insensitively', function (string $message, bool $detected) {
    $w = worklogWorld();

    $set = app(CandidateBuilder::class)->build($message, $w['today']);

    expect($set->detectedProjectIds === [$w['harbor']->id])->toBe($detected);
})->with([
    'name' => ['fix bug di harbor portal', true],
    'uppercase name' => ['HARBOR PORTAL deploy', true],
    'alias' => ['HP sso sudah jalan', true],
    'alias lowercase' => ['kerja hp hari ini', true],
    'alias inside another word' => ['HPV vaccine research', false],
    'name inside another word' => ['harbor portalx', false],
    'punctuation around' => ['(Harbor Portal): selesai', true],
    'no project' => ['fix bug login', false],
]);

it('falls back to compact mode across all active projects when no project is named', function () {
    $w = worklogWorld();

    $set = app(CandidateBuilder::class)->build('yang kemarin soal domain itu sudah beres', $w['today']);

    expect($set->isCompact())->toBeTrue()
        ->and($set->hasTask($w['menu']->id))->toBeTrue()
        ->and($set->hasTask($w['tracking']->id))->toBeTrue()
        ->and(array_keys($set->projects))->toBe([$w['harbor']->id, $w['kedai']->id]);

    $prompt = $set->toPrompt();
    expect($prompt['candidates_mode'])->toBe('compact')
        ->and($prompt['candidates'][0])->not->toHaveKey('recent_activities');
});

it('shows the last three activities newest first, shortened, and the people', function () {
    $w = worklogWorld();

    $task = app(CandidateBuilder::class)->build('Harbor Portal', $w['today'])->task($w['tracking']->id);

    expect($task['recent_activities'])->toHaveCount(3)
        ->and(array_column($task['recent_activities'], 'date'))->toBe(['2026-09-25', '2026-09-24', '2026-09-22'])
        ->and(mb_strlen($task['recent_activities'][0]['summary']))->toBe(200)
        ->and($task['people'])->toBe(['Doni'])
        ->and($task['status'])->toBe('in_progress');
});

it('offers a Completed task only inside the 30 day window', function () {
    $w = worklogWorld();
    $builder = app(CandidateBuilder::class);

    expect($builder->build('Harbor Portal', $w['today'])->hasTask($w['dock']->id))->toBeTrue()                       // 12 days ago
        ->and($builder->build('Harbor Portal', $w['today']->addDays(20))->hasTask($w['dock']->id))->toBeFalse();     // 32 days ago
});

it('returns nothing when the user has no active project', function () {
    actingAsUser();

    $set = app(CandidateBuilder::class)->build('halo', CarbonImmutable::parse('2026-09-30'));

    expect($set->tasks)->toBe([])->and($set->projects)->toBe([]);
});

it('never sees another user when the context changes', function () {
    $w = worklogWorld();

    $seenByStranger = app(App\Support\UserContext::class)->runAs($w['stranger']->id, fn () => app(CandidateBuilder::class)->build('Harbor Portal Clone', $w['today']));

    expect(candidateTitles($seenByStranger))->toBe(['Stranger Task']);
});

it('produces a prompt view with ids the validator can check', function () {
    $w = worklogWorld();
    $set = app(CandidateBuilder::class)->build('Harbor Portal', $w['today']);
    $prompt = $set->toPrompt();

    expect($prompt['candidates_mode'])->toBe('focused')
        ->and(array_column($prompt['candidates'], 'id'))->toEqualCanonicalizing(array_keys($set->tasks))
        ->and($prompt['candidates'][0])->toHaveKeys(['id', 'project_id', 'title', 'status', 'people', 'recent_activities']);
});
