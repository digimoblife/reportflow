<?php

use App\Enums\ActivityType;
use App\Enums\TaskStatus;
use App\Models\AiInteraction;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\Eval\EvalDataset;
use App\Services\Ai\Eval\EvalReport;
use App\Services\Ai\Eval\EvalRunner;
use App\Services\Ai\Eval\EvalSecrets;
use App\Services\Ai\Eval\OracleResponder;
use App\Services\Ai\Fakes\FakeAiProvider;
use App\Services\Redaction\RedactionService;
use Carbon\CarbonImmutable;

function sampleDataset(string $name = 'sample'): EvalDataset
{
    return EvalDataset::load(base_path($name === 'sample' ? 'tests/Eval/data-sample' : 'tests/Eval/data-'.$name), $name);
}

/** Run the sample dataset with a provider scripted per case by $respond(case, map, today) => json string. */
function runSample(?Closure $respond = null, ?FakeAiProvider $provider = null): EvalReport
{
    $provider ??= new FakeAiProvider;
    $oracle = new OracleResponder;
    $hook = function (array $case, $map, $today) use ($provider, $respond, $oracle): void {
        $provider->respondWith($respond ? $respond($case, $map, $today, $oracle) : $oracle->respond($case, $map, $today));
    };

    return app(EvalRunner::class)->run(sampleDataset(), $provider, 'worklog_extraction@v1', $hook);
}

describe('the bundled datasets', function () {
    it('are large enough and cover every hard case category of PRD §75', function (string $name) {
        $dataset = sampleDataset($name);
        $categories = array_unique(array_merge(...array_column($dataset->cases, 'categories')));

        expect(count($dataset->cases))->toBeGreaterThanOrEqual(50)
            ->and($categories)->toContain('vague', 'multi_item', 'cross_project', 'backdated', 'mixed_language', 'reopen', 'ambiguous', 'chitchat', 'credential', 'future');
    })->with(['sample', 'realistic']);

    it('are internally consistent: labels point at real snapshot keys and valid enum values', function (string $name) {
        $dataset = sampleDataset($name);
        $projects = array_column($dataset->snapshot['projects'], 'key');
        $tasks = array_column($dataset->snapshot['tasks'], 'key');

        foreach ($dataset->cases as $case) {
            foreach ($case['expected']['items'] as $item) {
                expect($item['project'])->toBeIn($projects, $case['id']);
                expect($item['task'] === 'new' || in_array($item['task'], $tasks, true))->toBeTrue($case['id']);
                expect(ActivityType::tryFrom($item['activity_type']))->not->toBeNull($case['id']);
                expect($item['status_to'] === null || TaskStatus::tryFrom($item['status_to']) !== null)->toBeTrue($case['id']);
            }
        }

        foreach ($dataset->snapshot['tasks'] as $task) {
            expect($task['project'])->toBeIn($projects)->and(TaskStatus::tryFrom($task['status']))->not->toBeNull();
        }
    })->with(['sample', 'realistic']);

    it('contain no secret-shaped text: fake credentials are placeholders expanded at runtime', function (string $name) {
        foreach (sampleDataset($name)->cases as $case) {
            $result = RedactionService::forUser(null)->redact($case['message']);

            expect($result->text)->toBe($case['message'], "{$case['id']} holds a literal secret");
        }

        expect(EvalSecrets::expand('a {{secret:github}} b'))->not->toContain('{{')
            ->and(RedactionService::forUser(null)->redact(EvalSecrets::expand('{{secret:github}} {{secret:openai}} {{secret:password}} {{secret:generic}}'))->secretCount())->toBe(4);
    })->with(['sample', 'realistic']);

    it('let the oracle score 100% on the realistic dataset too', function () {
        $dataset = sampleDataset('realistic');
        $provider = new FakeAiProvider;
        $oracle = new OracleResponder;

        $report = app(EvalRunner::class)->run($dataset, $provider, 'worklog_extraction@v1', fn ($case, $map, $today) => $provider->respondWith($oracle->respond($case, $map, $today)));

        expect($report->problemIds()['failed'])->toBe([])->and($report->problemIds()['wrong'])->toBe([]);
    });
});

describe('scoring', function () {
    it('scores 100% when the model returns exactly the labelled answer (harness sanity check)', function () {
        $report = runSample();

        foreach ($report->metrics() as $name => $metric) {
            expect($metric['rate'])->toBe($metric['total'] === 0 ? null : 1.0, $name);
        }

        expect($report->problemIds()['failed'])->toBe([])->and($report->problemIds()['wrong'])->toBe([])
            ->and($report->usage['calls'])->toBe(count(sampleDataset()->cases))
            ->and($report->metrics()['matching']['total'])->toBe(count(sampleDataset()->cases));
    });

    it('drops the right metrics when the model never records anything', function () {
        $report = runSample(fn () => FakeAiProvider::EMPTY_EXTRACTION);

        $withItems = count(array_filter(sampleDataset()->cases, fn ($c) => $c['expected']['items'] !== [] && ! ($c['expected']['ambiguous'] ?? false)));
        $metrics = $report->metrics();

        // Ambiguous cases are still "correct" (nothing was silently accepted); everything with real work is missed.
        expect($metrics['extraction']['correct'])->toBe($metrics['extraction']['total'] - $withItems)
            ->and($metrics['matching']['correct'])->toBe($metrics['matching']['total'] - $withItems)
            ->and($metrics['project']['correct'])->toBe($metrics['project']['total'] - $withItems)
            ->and($metrics['date']['correct'])->toBe(0)
            ->and($metrics['extraction']['met'])->toBeFalse();
    });

    it('reports a different activity type as a classification miss, not as a missed item', function () {
        $report = runSample(function (array $case, $map, $today, $oracle) {
            $json = json_decode($oracle->respond($case, $map, $today), true);
            foreach ($json['items'] as &$item) {
                $item['activity']['type'] = 'other';
            }

            return json_encode($json);
        });
        $metrics = $report->metrics();

        expect($metrics['extraction']['rate'])->toEqual(1)
            ->and($metrics['classification']['rate'])->toBeLessThan(0.5)
            ->and($report->cases[0]['detail']['predicted'][0])->toContain('|other|');
    });

    it('catches wrong task matches, and shows them in the confidence buckets', function () {
        $report = runSample(function (array $case, $map, $today, $oracle) {
            $json = json_decode($oracle->respond($case, $map, $today), true);

            foreach ($json['items'] as &$item) {
                if ($item['task_ref']['type'] === 'existing' && $item['task_ref']['task_id'] === $map->tasks['harbor:tracking']) {
                    $item['task_ref']['task_id'] = $map->tasks['harbor:invoice']; // wrong task, still confident and same project
                }
            }

            return json_encode($json);
        });

        $wrong = array_filter($report->cases, fn ($c) => ($c['scores']['matching'] ?? true) === false);

        expect(count($wrong))->toBeGreaterThan(0)
            ->and($report->confidenceBuckets()['high']['rate'])->toBeLessThan(1.0)
            ->and($report->metrics()['matching']['met'])->toBeFalse();
    });

    it('treats a silent pick on an ambiguous note as wrong, and a question as right', function () {
        $silentPick = runSample(function (array $case, $map, $today, $oracle) {
            if ($case['expected']['ambiguous'] ?? false) {
                return json_encode(['items' => [[
                    'intent' => 'update_existing_task', 'project_id' => $map->projects['harbor'], 'task_ref' => ['type' => 'existing', 'task_id' => $map->tasks['harbor:invoice']],
                    'confidence' => 0.97, 'matching_signals' => [], 'activity' => ['type' => 'other', 'summary' => 'Guess', 'date' => '2026-09-30', 'date_precision' => 'day'],
                    'status_change' => null, 'people' => [], 'missing_details' => [],
                ]], 'clarification_needed' => null]);
            }

            return $oracle->respond($case, $map, $today);
        });
        $ambiguousIds = array_column(array_filter(sampleDataset()->cases, fn ($c) => $c['expected']['ambiguous'] ?? false), 'id');
        $wrongIds = array_column(array_filter($silentPick->cases, fn ($c) => ($c['scores']['matching'] ?? true) === false), 'id');

        expect($wrongIds)->toEqualCanonicalizing($ambiguousIds);
    });

    it('applies backend rules: a future date is rejected and therefore not counted as a prediction', function () {
        $report = runSample(function (array $case, $map, $today, $oracle) {
            $json = json_decode($oracle->respond($case, $map, $today), true);
            foreach ($json['items'] as &$item) {
                $item['activity']['date'] = '2026-12-31';
            }

            return json_encode($json);
        });

        $future = array_values(array_filter($report->cases, fn ($c) => in_array('future', $c['categories'], true)));

        expect($future)->not->toBeEmpty()->and($future[0]['scores']['extraction'])->toBeTrue()  // expected nothing, predicted nothing
            ->and($report->metrics()['extraction']['met'])->toBeFalse();                        // everything else lost its item
    });

    it('records a case as failed when the model never returns valid JSON, and continues', function () {
        $report = runSample(fn () => 'I am sorry, I cannot help with that.');

        expect($report->problemIds()['failed'])->toHaveCount(count(sampleDataset()->cases))
            ->and($report->metrics()['matching']['total'])->toBe(0)
            ->and($report->cases[0]['reasons'])->toBe(['extraction_failed']);
    });

    it('records a case as failed on provider errors', function () {
        $provider = (new FakeAiProvider)->failWith(new AiProviderException('down'));

        $report = app(EvalRunner::class)->run(sampleDataset(), $provider, 'worklog_extraction@v1');

        expect($report->problemIds()['failed'])->toHaveCount(count(sampleDataset()->cases))
            ->and($report->cases[0]['reasons'])->toBe(['provider_error']);
    });

    it('compares two runs by case id', function () {
        $good = runSample()->toArray();
        $bad = runSample(fn () => FakeAiProvider::EMPTY_EXTRACTION);

        $diff = $bad->diff($good);

        expect($diff['improved'])->toBe([])->and(count($diff['regressed']))->toBeGreaterThan(0)
            ->and(runSample()->diff($bad->toArray())['improved'])->toBe($diff['regressed']);
    });
});

describe('safety', function () {
    it('leaves no trace in the database: everything is rolled back', function () {
        $before = [User::query()->count(), asSystem(fn () => Project::query()->count()), asSystem(fn () => Task::query()->count()), asSystem(fn () => AiInteraction::query()->count())];

        runSample();

        expect([User::query()->count(), asSystem(fn () => Project::query()->count()), asSystem(fn () => Task::query()->count()), asSystem(fn () => AiInteraction::query()->count())])->toBe($before);
    });

    it('never puts message text in a report', function () {
        $json = json_encode(runSample()->toArray());

        foreach (sampleDataset()->cases as $case) {
            expect($json)->not->toContain(mb_substr($case['message'], 0, 25));
        }
    });

    it('sends the provider only redacted text', function () {
        $provider = new FakeAiProvider;
        runSample(provider: $provider);

        $sent = implode("\n", array_map(fn ($r) => $r->user, $provider->requests));

        expect($sent)->toContain('[REDACTED_SECRET]')
            ->and($sent)->not->toContain('EVALFAKE')->not->toContain(str_repeat('E1v2', 9))->not->toContain('evalfake0123');
    });

    it('resolves "today" from the case, with candidates as of that date', function () {
        $provider = new FakeAiProvider;
        $dataset = sampleDataset();
        $dataset = new EvalDataset('t', $dataset->snapshot, [['id' => 'X1', 'categories' => ['basic'], 'today' => '2026-12-01', 'message' => 'Harbor: cek dock report', 'expected' => ['items' => []]]]);

        app(EvalRunner::class)->run($dataset, $provider, 'worklog_extraction@v1');
        $payload = json_decode($provider->requests[0]->user, true);

        // Dock Utilization Report was completed 2026-09-18: outside the 30 day window on 2026-12-01.
        expect($payload['today'])->toBe('2026-12-01')
            ->and(array_column($payload['candidates'], 'title'))->not->toContain('Dock Utilization Report');
    });

    it('runs the prompt version it was asked for, in the request and in the report', function (string $version, string $other) {
        $provider = new FakeAiProvider;
        $dataset = sampleDataset()->filter(ids: ['C001', 'C002']);

        $report = app(EvalRunner::class)->run($dataset, $provider, $version);
        $prompt = file_get_contents(resource_path('prompts/worklog_extraction/'.substr($version, -2).'.md'));

        expect($report->prompt)->toBe($version)
            ->and(array_unique(array_map(fn ($r) => $r->promptVersion, $provider->requests)))->toBe([$version])
            ->and(array_unique(array_map(fn ($r) => $r->system, $provider->requests)))->toBe([$prompt])
            ->and($version)->not->toBe($other);
    })->with([['worklog_extraction@v1', 'worklog_extraction@v2'], ['worklog_extraction@v2', 'worklog_extraction@v1']]);

    it('fails fast on an unknown prompt version', function () {
        expect(fn () => app(EvalRunner::class)->run(sampleDataset(), new FakeAiProvider, 'worklog_extraction@v9'))->toThrow(InvalidArgumentException::class);
    });
});

describe('dataset loading', function () {
    it('rejects broken input without echoing it', function (string $line) {
        $dir = sys_get_temp_dir().'/eval-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/snapshot.json', '{}');
        file_put_contents($dir.'/cases.jsonl', $line);

        try {
            EvalDataset::load($dir, 'broken');
            $this->fail('expected exception');
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->not->toContain('SECRET-CLIENT-TEXT');
        } finally {
            array_map('unlink', glob($dir.'/*'));
            rmdir($dir);
        }
    })->with([
        'invalid json' => ['{"id":"a","message":"SECRET-CLIENT-TEXT'],
        'missing fields' => ['{"id":"a","message":"SECRET-CLIENT-TEXT"}'],
        'duplicate ids' => ["{\"id\":\"a\",\"message\":\"SECRET-CLIENT-TEXT\",\"categories\":[],\"expected\":{\"items\":[]}}\n{\"id\":\"a\",\"message\":\"x\",\"categories\":[],\"expected\":{\"items\":[]}}"],
    ]);

    it('reports a missing dataset', function () {
        EvalDataset::load('/nonexistent/path', 'x');
    })->throws(InvalidArgumentException::class, 'not found');

    it('expands relative and absolute label dates', function () {
        $oracle = new OracleResponder;
        $today = CarbonImmutable::parse('2026-09-30');

        expect($oracle->date(0, $today))->toBe('2026-09-30')->and($oracle->date(-2, $today))->toBe('2026-09-28')->and($oracle->date('2026-08-15', $today))->toBe('2026-08-15');
    });
});
