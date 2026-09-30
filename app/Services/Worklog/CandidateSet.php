<?php

namespace App\Services\Worklog;

/**
 * The tasks (and projects) the AI may refer to for one message (PRD §12). Built by the backend,
 * shown to the model, and reused by the validator as the ONLY list of acceptable task ids.
 */
final readonly class CandidateSet
{
    /**
     * @param  array<int, array{id: int, name: string, aliases: list<string>}>  $projects  keyed by project id
     * @param  array<int, array{id: int, project_id: int, title: string, status: string, completed_at: string|null, last_activity_at: string|null, people: list<string>, recent_activities: list<array{date: string, type: string, summary: string}>}>  $tasks  keyed by task id
     * @param  list<int>  $detectedProjectIds  projects named in the message (empty = "compact" mode)
     */
    public function __construct(
        public array $projects,
        public array $tasks,
        public array $detectedProjectIds = [],
    ) {}

    public function isCompact(): bool
    {
        return $this->detectedProjectIds === [];
    }

    public function hasTask(int $id): bool
    {
        return isset($this->tasks[$id]);
    }

    /**
     * @return array{id: int, project_id: int, title: string, status: string, completed_at: string|null, last_activity_at: string|null, people: list<string>, recent_activities: list<array{date: string, type: string, summary: string}>}|null
     */
    public function task(int $id): ?array
    {
        return $this->tasks[$id] ?? null;
    }

    public function hasProject(int $id): bool
    {
        return isset($this->projects[$id]);
    }

    /**
     * The JSON-ready view sent to the model. Compact mode drops the activity history.
     *
     * @return array{projects: list<array<string, mixed>>, candidates_mode: string, candidates: list<array<string, mixed>>}
     */
    public function toPrompt(): array
    {
        $compact = $this->isCompact();

        return [
            'projects' => array_values($this->projects),
            'candidates_mode' => $compact ? 'compact' : 'focused',
            'candidates' => array_values(array_map(static function (array $task) use ($compact): array {
                $view = [
                    'id' => $task['id'],
                    'project_id' => $task['project_id'],
                    'title' => $task['title'],
                    'status' => $task['status'],
                    'people' => $task['people'],
                ];

                if (! $compact) {
                    $view['recent_activities'] = $task['recent_activities'];
                }

                return $view;
            }, $this->tasks)),
        ];
    }
}
