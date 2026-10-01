<?php

namespace App\Services\Report;

use App\Enums\Language;
use App\Enums\ReportType;
use Illuminate\Support\Facades\Lang;

/**
 * What the model is shown for ONE section, and the boundaries its answer is checked against (PRD §44): only the tasks
 * and activities that belong to that section, exact counts, and nothing else. Built from the frozen ReportDataSet.
 */
final readonly class ReportSectionPayload
{
    private const MAX_ACTIVITIES = 60;

    private const MAX_SUMMARY = 240;

    /**
     * @param  array<string, mixed>  $data  the JSON sent to the model
     * @param  array<int, string>  $titles  task id => title: the only ids a narrative may cite
     * @param  list<string>  $numberPool  numbers the narrative may use
     */
    private function __construct(
        public array $data,
        public array $titles,
        public array $numberPool,
    ) {}

    public static function build(string $section, ReportDataSet $data, ReportType $type, Language $language, ReportFactsBuilder $facts, ?string $instruction = null): self
    {
        $taskIds = match ($section) {
            'detailed' => array_values(array_unique(array_map(fn (array $a): int => $a['task_id'], $data->activities))),
            'ongoing' => [...$data->ongoingTaskIds, ...$data->waitingTaskIds],
            default => $data->taskIds(),
        };
        $taskIds = array_values(array_filter($taskIds, fn (int $id): bool => isset($data->tasks[$id])));
        sort($taskIds);
        $allowed = array_flip($taskIds);

        $tasks = [];
        $titles = [];

        foreach ($taskIds as $id) {
            $t = $data->tasks[$id];
            $titles[$id] = $t['title'];
            $tasks[] = [
                'id' => $id, 'title' => $t['title'], 'status' => $t['status'], 'waiting_reason' => $t['waiting_reason'],
                'started' => $t['started'], 'completed' => $t['completed'], 'people' => $t['people'],
            ];
        }

        $activities = [];

        foreach ($data->activities as $a) {
            if (isset($allowed[$a['task_id']]) && count($activities) < self::MAX_ACTIVITIES) {
                $activities[] = ['id' => $a['id'], 'task_id' => $a['task_id'], 'date' => $a['date'], 'type' => $a['type'], 'summary' => mb_substr($a['summary'], 0, self::MAX_SUMMARY)];
            }
        }

        $counts = $data->counts();
        $payload = [
            'language' => $language->value,
            'section' => ['key' => $section, 'title' => (string) Lang::get('report.sections.'.$section, [], $language->value)],
            'project' => $data->projectName,
            'period' => ['label' => $facts->periodLabel($type, $data->periodStart, $data->periodEnd, $language), 'start' => $data->periodStart, 'end' => $data->periodEnd],
            'counts' => $counts,
            'tasks' => $tasks,
            'activities' => $activities,
        ];

        if ($instruction !== null && trim($instruction) !== '') {
            $payload['instruction'] = $instruction;
        }

        return new self($payload, $titles, self::numbers(json_encode(self::withoutIds($payload), JSON_UNESCAPED_UNICODE) ?: ''));
    }

    /**
     * @return list<int>
     */
    public function taskIds(): array
    {
        return array_map('intval', array_keys($this->titles));
    }

    /**
     * Number tokens of a text ("12", "2026", "3.5"); identifiers inside {{task:ID}} tokens are not numbers.
     *
     * @return list<string>
     */
    public static function numbers(string $text): array
    {
        $text = (string) preg_replace('/\{\{task:\d+\}\}/', ' ', $text);
        preg_match_all('/(?<![\w.])\d+(?:[.,]\d+)?/u', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Ids are bookkeeping, not facts: they must not widen the set of numbers the narrative may state.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function withoutIds(array $payload): array
    {
        foreach (['tasks', 'activities'] as $key) {
            foreach ($payload[$key] as $i => $row) {
                unset($row['id'], $row['task_id']);
                $payload[$key][$i] = $row;
            }
        }

        return $payload;
    }
}
