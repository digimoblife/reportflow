<?php

namespace App\Services\Ai\Eval;

use Carbon\CarbonImmutable;

/**
 * Builds the JSON a perfect model would return for a labelled case. Used by `eval:run --provider=fake`
 * to prove that the harness, the validator and the metrics agree with the labels (expect 100%).
 */
final class OracleResponder
{
    /**
     * @param  array<string, mixed>  $case
     */
    public function respond(array $case, SnapshotMap $map, CarbonImmutable $today): string
    {
        $expected = $case['expected'];

        if ($expected['ambiguous'] ?? false) {
            return (string) json_encode(['items' => [], 'clarification_needed' => ['question' => 'Which task do you mean?', 'options' => ['A', 'B']]]);
        }

        $items = [];

        foreach ($expected['items'] as $item) {
            $isNew = $item['task'] === 'new';
            $statusTo = $item['status_to'] ?? null;

            $items[] = [
                'intent' => $isNew ? 'new_task' : 'update_existing_task',
                'project_id' => isset($item['project']) ? $map->projects[$item['project']] : null,
                'task_ref' => $isNew
                    ? ['type' => 'new', 'title' => $item['title'] ?? 'Oracle task']
                    : ['type' => 'existing', 'task_id' => $map->tasks[$item['task']]],
                'confidence' => 0.95,
                'matching_signals' => ['oracle'],
                'activity' => [
                    'type' => $item['activity_type'],
                    'summary' => 'Oracle summary',
                    'date' => $this->date($item['date'] ?? 0, $today),
                    'date_precision' => $item['date_precision'] ?? 'day',
                ],
                'status_change' => $statusTo === null ? null : ['from' => $isNew ? null : $map->taskStatus[$item['task']], 'to' => $statusTo],
                'people' => [],
                'missing_details' => [],
            ];
        }

        return (string) json_encode(['items' => $items, 'clarification_needed' => null], JSON_UNESCAPED_UNICODE);
    }

    /**
     * A label date is an offset in days from "today" (int) or an absolute YYYY-MM-DD string.
     */
    public function date(int|string $value, CarbonImmutable $today): string
    {
        return is_int($value) ? $today->addDays($value)->format('Y-m-d') : $value;
    }
}
