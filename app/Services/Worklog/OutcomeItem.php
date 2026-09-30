<?php

namespace App\Services\Worklog;

use App\Enums\OutcomeState;

/**
 * What happened to one AI item. Serialised into inbound_messages.outcome (ids, codes, and for pending items
 * the validated item, whose text derives from the already redacted message).
 */
final readonly class OutcomeItem
{
    /**
     * @param  list<string>  $reasons
     * @param  list<int>  $options  candidate task ids offered for a "match" question
     * @param  array<string, mixed>|null  $pending  the schema-valid item, kept until the user answers
     */
    public function __construct(
        public int $index,
        public OutcomeState $state,
        public array $reasons = [],
        public ?int $projectId = null,
        public ?int $taskId = null,
        public bool $createdTask = false,
        public ?int $activityId = null,
        public ?string $statusFrom = null,
        public ?string $statusTo = null,
        public bool $reopened = false,
        public ?int $taskVersion = null,
        public ?string $question = null,
        public array $options = [],
        public ?array $pending = null,
        public ?string $pendingDate = null,
        public ?string $level = null,
        public ?int $questionMessageId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'state' => $this->state->value,
            'reasons' => $this->reasons,
            'project_id' => $this->projectId,
            'task_id' => $this->taskId,
            'created_task' => $this->createdTask,
            'activity_id' => $this->activityId,
            'status_from' => $this->statusFrom,
            'status_to' => $this->statusTo,
            'reopened' => $this->reopened,
            'task_version' => $this->taskVersion,
            'question' => $this->question,
            'options' => $this->options,
            'pending' => $this->pending,
            'pending_date' => $this->pendingDate,
            'level' => $this->level,
            'question_message_id' => $this->questionMessageId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            index: (int) $data['index'],
            state: OutcomeState::from((string) $data['state']),
            reasons: array_values(array_map('strval', (array) ($data['reasons'] ?? []))),
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            taskId: isset($data['task_id']) ? (int) $data['task_id'] : null,
            createdTask: (bool) ($data['created_task'] ?? false),
            activityId: isset($data['activity_id']) ? (int) $data['activity_id'] : null,
            statusFrom: isset($data['status_from']) ? (string) $data['status_from'] : null,
            statusTo: isset($data['status_to']) ? (string) $data['status_to'] : null,
            reopened: (bool) ($data['reopened'] ?? false),
            taskVersion: isset($data['task_version']) ? (int) $data['task_version'] : null,
            question: isset($data['question']) ? (string) $data['question'] : null,
            options: array_values(array_map('intval', (array) ($data['options'] ?? []))),
            pending: isset($data['pending']) && is_array($data['pending']) ? $data['pending'] : null,
            pendingDate: isset($data['pending_date']) ? (string) $data['pending_date'] : null,
            level: isset($data['level']) ? (string) $data['level'] : null,
            questionMessageId: isset($data['question_message_id']) ? (int) $data['question_message_id'] : null,
        );
    }

    /**
     * A copy with some fields replaced (readonly objects are rebuilt, never mutated).
     *
     * @param  array<string, mixed>  $changes  keys of toArray()
     */
    public function with(array $changes): self
    {
        return self::fromArray($changes + $this->toArray());
    }
}
