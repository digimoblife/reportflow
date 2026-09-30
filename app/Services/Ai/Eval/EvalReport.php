<?php

namespace App\Services\Ai\Eval;

/**
 * Aggregated evaluation results (PRD §73 targets, §75 categories). Holds per-case booleans and ids only:
 * never message text, so a report can be printed or shared without leaking dataset content.
 */
final class EvalReport
{
    /** PRD §73 engineering targets. Null = reported, no target. */
    public const TARGETS = ['extraction' => 0.90, 'project' => 0.95, 'matching' => 0.90, 'date' => 0.95, 'status' => null, 'rules' => null];

    public const LABELS = [
        'extraction' => 'Worklog extraction',
        'project' => 'Project identification',
        'matching' => 'Task matching',
        'date' => 'Date extraction',
        'status' => 'Status detection',
        'rules' => 'Backend rules (expected decision)',
    ];

    /**
     * @param  list<array{id: string, categories: list<string>, review: bool, failed: bool, scores: array<string, bool|null>, min_confidence: float|null, reasons: list<string>}>  $cases
     * @param  array{calls: int, tokens_input: int, tokens_output: int, avg_latency_ms: int|null}  $usage
     * @param  array{high: float, medium: float}  $thresholds
     */
    public function __construct(
        public readonly string $prompt,
        public readonly string $provider,
        public readonly string $dataset,
        public readonly array $cases,
        public readonly array $usage,
        public readonly array $thresholds,
    ) {}

    /**
     * @return array<string, array{correct: int, total: int, rate: float|null, target: float|null, met: bool|null}>
     */
    public function metrics(?string $category = null): array
    {
        $metrics = [];

        foreach (self::TARGETS as $name => $target) {
            $correct = $total = 0;

            foreach ($this->cases as $case) {
                if ($category !== null && ! in_array($category, $case['categories'], true)) {
                    continue;
                }

                $score = $case['scores'][$name] ?? null;

                if ($score === null) {
                    continue;
                }

                $total++;
                $correct += $score ? 1 : 0;
            }

            $rate = $total === 0 ? null : $correct / $total * 1.0;
            $metrics[$name] = ['correct' => $correct, 'total' => $total, 'rate' => $rate, 'target' => $target, 'met' => ($rate === null || $target === null) ? null : $rate >= $target];
        }

        return $metrics;
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        $all = array_unique(array_merge(...array_map(fn (array $c): array => $c['categories'], $this->cases)));
        sort($all);

        return $all;
    }

    /**
     * Task-matching accuracy per model-confidence bucket (uses the lowest confidence in a case). The
     * model's confidence is uncalibrated (PRD §13): this is the data to retune the thresholds.
     *
     * @return array<string, array{correct: int, total: int, rate: float|null}>
     */
    public function confidenceBuckets(): array
    {
        $buckets = ['high' => [0, 0], 'medium' => [0, 0], 'low' => [0, 0]];

        foreach ($this->cases as $case) {
            if ($case['min_confidence'] === null || ($case['scores']['matching'] ?? null) === null) {
                continue;
            }

            $bucket = $case['min_confidence'] >= $this->thresholds['high'] ? 'high' : ($case['min_confidence'] >= $this->thresholds['medium'] ? 'medium' : 'low');
            $buckets[$bucket][1]++;
            $buckets[$bucket][0] += $case['scores']['matching'] ? 1 : 0;
        }

        return array_map(fn (array $b): array => ['correct' => $b[0], 'total' => $b[1], 'rate' => $b[1] === 0 ? null : $b[0] / $b[1] * 1.0], $buckets);
    }

    /**
     * Ids of cases where the pipeline itself failed, or any metric was wrong.
     *
     * @return array{failed: list<string>, wrong: list<string>, review: list<string>}
     */
    public function problemIds(): array
    {
        $failed = $wrong = $review = [];

        foreach ($this->cases as $case) {
            if ($case['failed']) {
                $failed[] = $case['id'];
            } elseif (in_array(false, $case['scores'], true)) {
                $wrong[] = $case['id'];
            }

            if ($case['review']) {
                $review[] = $case['id'];
            }
        }

        return ['failed' => $failed, 'wrong' => $wrong, 'review' => $review];
    }

    /**
     * Compare with an earlier report's toArray(): case ids that improved or regressed (no text).
     *
     * @param  array<string, mixed>  $baseline
     * @return array{improved: list<string>, regressed: list<string>}
     */
    public function diff(array $baseline): array
    {
        $before = [];
        foreach ((array) ($baseline['cases'] ?? []) as $case) {
            $before[$case['id']] = $case;
        }

        $improved = $regressed = [];

        foreach ($this->cases as $case) {
            if (! isset($before[$case['id']])) {
                continue;
            }

            $old = $this->passCount($before[$case['id']]);
            $new = $this->passCount($case);

            if ($new > $old) {
                $improved[] = $case['id'];
            } elseif ($new < $old) {
                $regressed[] = $case['id'];
            }
        }

        return ['improved' => $improved, 'regressed' => $regressed];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prompt' => $this->prompt,
            'provider' => $this->provider,
            'dataset' => $this->dataset,
            'case_count' => count($this->cases),
            'metrics' => $this->metrics(),
            'categories' => array_combine($this->categories(), array_map(fn (string $c): array => $this->metrics($c), $this->categories())),
            'confidence_buckets' => $this->confidenceBuckets(),
            'problems' => $this->problemIds(),
            'usage' => $this->usage,
            'cases' => $this->cases,
        ];
    }

    /**
     * @param  array<string, mixed>  $case
     */
    private function passCount(array $case): int
    {
        if ($case['failed']) {
            return 0;
        }

        return count(array_filter((array) $case['scores'], fn ($s): bool => $s === true));
    }
}
