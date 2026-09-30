<?php

namespace App\Console\Commands;

use App\Services\Ai\DeepSeekProvider;
use App\Services\Ai\Eval\EvalDataset;
use App\Services\Ai\Eval\EvalReport;
use App\Services\Ai\Eval\EvalRunner;
use App\Services\Ai\Eval\OracleResponder;
use App\Services\Ai\Eval\SnapshotMap;
use App\Services\Ai\Fakes\FakeAiProvider;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * PRD §75: measure the extraction pipeline against a labelled dataset. Prints numbers and case ids only,
 * never message text. Runs in a rolled-back transaction (see EvalRunner). See skill `prompt-eval`.
 */
#[Signature('eval:run
    {--prompt= : Prompt reference name@vN (default: config ai.extraction.prompt)}
    {--dataset=sample : "sample" (tests/Eval/data-sample, small synthetic), "realistic" (tests/Eval/data-realistic, larger synthetic, real-looking chat style) or "local" (tests/Eval/data, your real data)}
    {--provider=fake : "fake" (an oracle that answers from the labels: checks the harness) or "deepseek" (real model)}
    {--send-to-deepseek : Required with --provider=deepseek: confirms that the dataset messages may be sent to DeepSeek}
    {--json : Print the report as JSON}
    {--out= : Also write the JSON report to this file}
    {--baseline= : JSON report of an earlier run: list improved and regressed case ids}')]
#[Description('Evaluate the worklog extraction pipeline against a labelled dataset (PRD §73, §75)')]
class EvalRun extends Command
{
    public function handle(EvalRunner $runner): int
    {
        if (app()->isProduction()) {
            $this->components->error('eval:run is not allowed in production.');

            return self::FAILURE;
        }

        try {
            $dataset = $this->dataset((string) $this->option('dataset'));
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $promptReference = (string) ($this->option('prompt') ?: config('ai.extraction.prompt'));
        $provider = (string) $this->option('provider');
        $beforeCase = null;

        if ($provider === 'deepseek') {
            if (! $this->option('send-to-deepseek')) {
                $this->components->error(count($dataset->cases).' dataset messages would be sent to DeepSeek. Add --send-to-deepseek to confirm.');

                return self::FAILURE;
            }

            if (! is_string(config('ai.deepseek.api_key')) || config('ai.deepseek.api_key') === '') {
                $this->components->error('DEEPSEEK_API_KEY is not set.');

                return self::FAILURE;
            }

            $ai = new DeepSeekProvider;
        } elseif ($provider === 'fake') {
            $fake = new FakeAiProvider;
            $oracle = new OracleResponder;
            $beforeCase = function (array $case, SnapshotMap $map, CarbonImmutable $today) use ($fake, $oracle): void {
                $fake->respondWith($oracle->respond($case, $map, $today));
            };
            $ai = $fake;
        } else {
            $this->components->error('--provider must be "fake" or "deepseek".');

            return self::FAILURE;
        }

        try {
            $report = $runner->run($dataset, $ai, $promptReference, $beforeCase);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $json = json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if ($this->option('out')) {
            file_put_contents((string) $this->option('out'), $json);
        }

        $diff = $this->diff($report);

        if ($this->option('json')) {
            $this->line($json);
        } else {
            $this->render($report, $diff);
        }

        return self::SUCCESS;
    }

    private function dataset(string $name): EvalDataset
    {
        $dir = match ($name) {
            'sample' => base_path('tests/Eval/data-sample'),
            'realistic' => base_path('tests/Eval/data-realistic'),
            'local' => base_path('tests/Eval/data'),
            default => throw new InvalidArgumentException('--dataset must be "sample", "realistic" or "local".'),
        };

        return EvalDataset::load($dir, $name);
    }

    /**
     * @return array{improved: list<string>, regressed: list<string>}|null
     */
    private function diff(EvalReport $report): ?array
    {
        $file = $this->option('baseline');

        if (! is_string($file) || $file === '') {
            return null;
        }

        $baseline = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($baseline) ? $report->diff($baseline) : null;
    }

    /**
     * @param  array{improved: list<string>, regressed: list<string>}|null  $diff
     */
    private function render(EvalReport $report, ?array $diff): void
    {
        $this->line(sprintf('Prompt %s | provider %s | dataset %s | %d cases', $report->prompt, $report->provider, $report->dataset, count($report->cases)));

        if ($report->provider === 'fake') {
            $this->components->warn('Provider "fake" is an oracle answering from the labels: 100% only proves the harness and validator agree with the labels. It says nothing about model quality.');
        }

        $percent = fn (?float $rate): string => $rate === null ? 'n/a' : number_format($rate * 100, 1).'%';

        $this->table(['Metric', 'Correct', 'Rate', 'Target', 'Met'], array_map(
            fn (string $name, array $m): array => [
                EvalReport::LABELS[$name], "{$m['correct']}/{$m['total']}", $percent($m['rate']),
                $m['target'] === null ? '-' : $percent($m['target']), $m['met'] === null ? '-' : ($m['met'] ? 'yes' : 'NO'),
            ],
            array_keys($report->metrics()),
            $report->metrics(),
        ));

        $rows = [];
        foreach ($report->categories() as $category) {
            $m = $report->metrics($category);
            $rows[] = [$category, ...array_map(fn (string $n): string => $percent($m[$n]['rate']).' ('.$m[$n]['total'].')', ['extraction', 'classification', 'project', 'matching', 'date', 'status'])];
        }
        $this->line('By case category (n in brackets):');
        $this->table(['Category', 'Extraction', 'Type', 'Project', 'Matching', 'Date', 'Status'], $rows);

        $this->line(sprintf('Task matching by model confidence (thresholds %.2f / %.2f):', $report->thresholds['high'], $report->thresholds['medium']));
        $this->table(['Bucket', 'Correct', 'Rate'], array_map(
            fn (string $b, array $v): array => [$b, "{$v['correct']}/{$v['total']}", $percent($v['rate'])],
            array_keys($report->confidenceBuckets()),
            $report->confidenceBuckets(),
        ));

        $problems = $report->problemIds();
        $this->line('Pipeline failures: '.($problems['failed'] === [] ? 'none' : implode(', ', $problems['failed'])));
        $this->line('Cases with a wrong metric: '.($problems['wrong'] === [] ? 'none' : implode(', ', $problems['wrong'])));
        $this->line('Labels flagged for human review: '.($problems['review'] === [] ? 'none' : implode(', ', $problems['review'])));
        $this->line(sprintf('Usage: %d AI calls, %d input / %d output tokens, avg latency %s ms', $report->usage['calls'], $report->usage['tokens_input'], $report->usage['tokens_output'], $report->usage['avg_latency_ms'] ?? 'n/a'));

        if ($diff !== null) {
            $this->line('Improved vs baseline: '.($diff['improved'] === [] ? 'none' : implode(', ', $diff['improved'])));
            $this->line('Regressed vs baseline: '.($diff['regressed'] === [] ? 'none' : implode(', ', $diff['regressed'])));
        }
    }
}
