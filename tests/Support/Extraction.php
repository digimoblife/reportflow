<?php

namespace Tests\Support;

/**
 * Builders for AI extraction payloads in tests.
 */
final class Extraction
{
    /**
     * A schema-valid "update existing task" item; override any field.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function item(int $taskId, ?int $projectId, array $overrides = []): array
    {
        return array_replace_recursive([
            'intent' => 'update_existing_task',
            'project_id' => $projectId,
            'task_ref' => ['type' => 'existing', 'task_id' => $taskId],
            'confidence' => 0.95,
            'matching_signals' => ['same_project'],
            'activity' => ['type' => 'development', 'summary' => 'Worked on it', 'date' => '2026-09-30', 'date_precision' => 'day'],
            'status_change' => null,
            'people' => [],
            'missing_details' => [],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function newItem(?int $projectId, string $title = 'Brand new task', array $overrides = []): array
    {
        $base = self::item(1, $projectId);
        unset($base['task_ref']); // do not merge the "existing" reference into the "new" one

        return array_replace_recursive($base, [
            'intent' => 'new_task',
            'task_ref' => ['type' => 'new', 'title' => $title],
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function payload(array $items, ?array $clarification = null): array
    {
        return ['items' => $items, 'clarification_needed' => $clarification];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public static function json(array $items, ?array $clarification = null): string
    {
        return json_encode(self::payload($items, $clarification), JSON_THROW_ON_ERROR);
    }
}
