<?php

namespace App\Services\Ai\Eval;

/**
 * Dataset keys <-> database ids for one eval run.
 */
final class SnapshotMap
{
    /**
     * @param  array<string, int>  $projects
     * @param  array<string, int>  $tasks
     * @param  array<string, string>  $taskStatus  task key => status
     */
    public function __construct(
        public readonly array $projects,
        public readonly array $tasks,
        public readonly array $taskStatus,
    ) {}

    public function projectKey(?int $id): ?string
    {
        $key = array_search($id, $this->projects, true);

        return $key === false ? null : (string) $key;
    }

    public function taskKey(?int $id): ?string
    {
        $key = array_search($id, $this->tasks, true);

        return $key === false ? null : (string) $key;
    }
}
