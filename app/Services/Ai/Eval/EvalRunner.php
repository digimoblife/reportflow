<?php

namespace App\Services\Ai\Eval;

use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\AiExtractionFailed;
use App\Services\Ai\AiInteractionRecorder;
use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AIService;
use App\Services\Ai\BatchAiProvider;
use App\Services\Ai\ExtractionSchema;
use App\Services\Ai\PrefetchingProvider;
use App\Services\Ai\PromptRepository;
use App\Services\Redaction\RedactionService;
use App\Services\Worklog\CandidateBuilder;
use App\Services\Worklog\Extraction\ExtractionValidator;
use App\Services\Worklog\Extraction\ItemDecision;
use App\Services\Worklog\Extraction\ValidatedItem;
use App\Services\Worklog\ProposalApplier;
use App\Support\UserContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs a labelled dataset through the production pipeline (redaction -> candidates -> AIService ->
 * validator) and scores it (PRD §73, §75). Everything happens inside a database transaction that is
 * ALWAYS rolled back, for a throw-away user, so a run leaves no rows behind.
 */
class EvalRunner
{
    public function __construct(
        private readonly SnapshotLoader $loader,
        private readonly CandidateBuilder $candidates,
        private readonly ExtractionValidator $validator,
        private readonly PromptRepository $prompts,
        private readonly ExtractionSchema $schema,
        private readonly AiInteractionRecorder $recorder,
        private readonly UserContext $context,
    ) {}

    /**
     * @param  (Closure(array<string, mixed> $case, SnapshotMap $map, CarbonImmutable $today): void)|null  $beforeCase  hook used to script a fake provider per case
     */
    public function run(EvalDataset $dataset, AiProvider $provider, string $promptReference, ?Closure $beforeCase = null, int $concurrency = 1): EvalReport
    {
        $this->prompts->load($promptReference); // fail fast on an unknown version

        $prefetcher = $concurrency > 1 && $provider instanceof BatchAiProvider ? new PrefetchingProvider($provider) : null;
        $ai = new AIService($prefetcher ?? $provider, $this->prompts, $this->schema, $this->recorder);
        $results = [];
        $usage = ['calls' => 0, 'tokens_input' => 0, 'tokens_output' => 0, 'avg_latency_ms' => null];

        DB::beginTransaction();

        try {
            $user = User::query()->create([
                'name' => 'Eval user',
                'email' => 'eval-'.Str::lower(Str::random(12)).'@eval.invalid',
                'password' => Str::random(40),
                'timezone' => (string) ($dataset->snapshot['timezone'] ?? 'Asia/Jakarta'),
            ]);

            $this->context->runAs($user->id, function () use ($dataset, $ai, $beforeCase, $prefetcher, $concurrency, $promptReference, &$results, &$usage): void {
                $map = $this->loader->load($dataset->snapshot);

                if ($prefetcher !== null) {
                    $this->prefetch($dataset, $ai, $prefetcher, $concurrency, $promptReference);
                }

                foreach ($dataset->cases as $case) {
                    $results[] = $this->runCase($case, $dataset, $map, $ai, $beforeCase, $promptReference);
                }

                $rows = AiInteraction::query()->get(['tokens_input', 'tokens_output', 'latency_ms']);
                $usage = [
                    'calls' => $rows->count(),
                    'tokens_input' => (int) $rows->sum('tokens_input'),
                    'tokens_output' => (int) $rows->sum('tokens_output'),
                    'avg_latency_ms' => $rows->whereNotNull('latency_ms')->isEmpty() ? null : (int) round((float) $rows->whereNotNull('latency_ms')->avg('latency_ms')),
                ];
            });
        } finally {
            DB::rollBack();
        }

        return new EvalReport(
            $promptReference,
            $provider->name(),
            $dataset->name,
            $results,
            $usage,
            ['high' => (float) config('ai.confidence.high', 0.90), 'medium' => (float) config('ai.confidence.medium', 0.70)],
        );
    }

    /**
     * Build every case's first request (same redaction and candidates as the real run) and fetch them
     * concurrently; the sequential pass afterwards then reads each answer from the prefetcher.
     */
    private function prefetch(EvalDataset $dataset, AIService $ai, PrefetchingProvider $prefetcher, int $concurrency, string $promptReference): void
    {
        $requests = [];

        foreach ($dataset->cases as $case) {
            $today = $this->today($case, $dataset);
            $message = RedactionService::forUser(null)->redact(EvalSecrets::expand((string) $case['message']))->text;
            $requests[] = $ai->buildRequest($message, $this->candidates->build($message, $today), $today, $promptReference);
        }

        $prefetcher->prefetch($requests, min(16, $concurrency));
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function today(array $case, EvalDataset $dataset): CarbonImmutable
    {
        $timezone = (string) ($dataset->snapshot['timezone'] ?? 'Asia/Jakarta');

        return CarbonImmutable::parse((string) ($case['today'] ?? $dataset->snapshot['today'] ?? '2026-09-30'), $timezone)->startOfDay();
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array{id: string, categories: list<string>, review: bool, failed: bool, scores: array<string, bool|null>, min_confidence: float|null, reasons: list<string>, detail: array{expected: list<string>, predicted: list<string>}}
     */
    private function runCase(array $case, EvalDataset $dataset, SnapshotMap $map, AIService $ai, ?Closure $beforeCase, string $promptReference): array
    {
        $today = $this->today($case, $dataset);

        if ($beforeCase !== null) {
            $beforeCase($case, $map, $today);
        }

        // Same order as production: expand fake secrets, redact, then the pipeline sees only redacted text.
        $message = RedactionService::forUser(null)->redact(EvalSecrets::expand((string) $case['message']))->text;

        $base = [
            'id' => (string) $case['id'],
            'categories' => array_values(array_map('strval', (array) $case['categories'])),
            'review' => (bool) ($case['review'] ?? false),
        ];

        try {
            $set = $this->candidates->build($message, $today);
            $outcome = $ai->extractWorklog($message, $set, $today, null, $promptReference);
            $proposal = $this->validator->validate($outcome->data, $set, $today);
        } catch (AiExtractionFailed|AiProviderException $e) {
            return $base + ['failed' => true, 'scores' => [], 'min_confidence' => null, 'reasons' => [$e instanceof AiExtractionFailed ? 'extraction_failed' : 'provider_error'], 'detail' => ['expected' => [], 'predicted' => []]];
        }

        $predicted = array_values(array_filter($proposal->items, fn (ValidatedItem $i): bool => $i->decision !== ItemDecision::Rejected));
        $confidences = array_map(fn (ValidatedItem $i): float => (float) $i->data['confidence'], $predicted);
        $reasons = array_values(array_unique(array_merge(...array_map(fn (ValidatedItem $i): array => $i->reasons, $proposal->items) ?: [[]])));

        [$scores, $detail] = $this->score($case, $predicted, $proposal->clarification !== null, $proposal->items, $map, $today);

        return $base + [
            'failed' => false,
            'scores' => $scores,
            'min_confidence' => $confidences === [] ? null : min($confidences),
            'reasons' => $reasons,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     * @param  list<ValidatedItem>  $predicted  items the backend did not reject
     * @param  list<ValidatedItem>  $all
     * @return array{0: array<string, bool|null>, 1: array{expected: list<string>, predicted: list<string>}}
     */
    private function score(array $case, array $predicted, bool $hasClarification, array $all, SnapshotMap $map, CarbonImmutable $today): array
    {
        $expected = $case['expected'];
        $oracle = new OracleResponder;
        $scores = ['extraction' => null, 'classification' => null, 'project' => null, 'matching' => null, 'date' => null, 'status' => null, 'rules' => null];

        if ($expected['ambiguous'] ?? false) {
            // Correct = the system did not silently pick a task: it asks, or nothing was accepted.
            $accepted = array_filter($predicted, fn (ValidatedItem $i): bool => $i->decision === ItemDecision::Accepted);
            $scores['matching'] = $hasClarification || $accepted === [];

            return [$scores, ['expected' => ['ambiguous'], 'predicted' => array_map(fn (ValidatedItem $i): string => $i->decision->value, $predicted)]];
        }

        $exp = array_map(fn (array $i): array => [
            'project' => $i['project'] ?? 'unknown',
            'ref' => $i['task'] === 'new' ? 'new' : 'existing:'.$i['task'],
            'type' => $i['activity_type'],
            'status' => $this->effectiveStatus($i['status_to'] ?? '-', 'existing:'.$i['task'], $i['task'], $i['activity_type'], $map),
            'date' => $oracle->date($i['date'] ?? 0, $today),
        ], $expected['items']);

        $got = array_map(fn (ValidatedItem $i): array => [
            'project' => $map->projectKey($i->projectId) ?? 'unknown',
            'ref' => $i->taskId === null ? 'new' : 'existing:'.($map->taskKey($i->taskId) ?? '?'),
            'type' => (string) $i->data['activity']['type'],
            'status' => $this->effectiveStatus($i->statusChange['to'] ?? '-', $i->taskId === null ? 'new' : 'existing', (string) ($map->taskKey($i->taskId ?? 0) ?? ''), (string) $i->data['activity']['type'], $map),
            'date' => (string) $i->activityDate?->format('Y-m-d'),
        ], $predicted);

        $column = fn (array $rows, string $key): array => array_column($rows, $key);
        $same = function (array $a, array $b): bool {
            sort($a);
            sort($b);

            return $a === $b;
        };

        $scores['project'] = $same($column($exp, 'project'), $column($got, 'project'));
        // Extraction = it found the right number of separate pieces of work; classification = it typed them the same way
        // as the label (activity types are partly subjective, so the two are reported apart).
        $scores['extraction'] = count($exp) === count($got);
        $scores['classification'] = count($exp) === count($got) && $same($column($exp, 'type'), $column($got, 'type'));
        $scores['matching'] = $same($column($exp, 'ref'), $column($got, 'ref'));

        if ($exp !== []) {
            $scores['date'] = $same($column($exp, 'date'), $column($got, 'date'));
            $scores['status'] = $same($column($exp, 'status'), $column($got, 'status'));
        }

        if (isset($expected['decision'])) {
            [$decision, $reason] = array_pad(explode(':', (string) $expected['decision'], 2), 2, null);
            $scores['rules'] = (bool) array_filter($all, fn (ValidatedItem $i): bool => $i->decision->value === $decision && ($reason === null || $i->has($reason)));
        }

        $line = fn (array $r): string => "{$r['project']}|{$r['ref']}|{$r['type']}|{$r['status']}|{$r['date']}";

        return [$scores, ['expected' => array_values(array_map($line, $exp)), 'predicted' => array_map($line, $got)]];
    }

    /**
     * Real work on an Open task moves it to In Progress in the backend (ProposalApplier, M4), whatever the model says,
     * so "in_progress" on such an item is not a model decision and is scored as "no status change" on both sides.
     */
    private function effectiveStatus(string $status, string $ref, string $taskKey, string $type, SnapshotMap $map): string
    {
        if ($status === 'in_progress' && $ref !== 'new' && ($map->taskStatus[$taskKey] ?? null) === 'open' && in_array($type, ProposalApplier::WORK_TYPES, true)) {
            return '-';
        }

        return $status;
    }
}
