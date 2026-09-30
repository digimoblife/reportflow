<?php

namespace App\Services\Worklog\Extraction;

use App\Enums\TaskStatus;
use Carbon\CarbonImmutable;

/**
 * Combines the model's (uncalibrated) confidence with deterministic backend signals (PRD §13).
 * Thresholds 0.90 / 0.70 are the PRD's starting values, kept in config('ai.confidence') so the
 * evaluation dataset can retune them without code changes.
 */
final class ConfidencePolicy
{
    public function __construct(
        private readonly float $high = 0.90,
        private readonly float $medium = 0.70,
        private readonly int $staleDays = 90,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            (float) config('ai.confidence.high', 0.90),
            (float) config('ai.confidence.medium', 0.70),
            (int) config('ai.extraction.stale_task_days', 90),
        );
    }

    public function level(float $confidence): ConfidenceLevel
    {
        return match (true) {
            $confidence >= $this->high => ConfidenceLevel::High,
            $confidence >= $this->medium => ConfidenceLevel::Medium,
            default => ConfidenceLevel::Low,
        };
    }

    /**
     * High confidence with a contradicting deterministic signal is treated as Medium.
     *
     * @param  list<string>  $contradictions
     */
    public function combine(ConfidenceLevel $level, array $contradictions): ConfidenceLevel
    {
        return $level === ConfidenceLevel::High && $contradictions !== [] ? ConfidenceLevel::Medium : $level;
    }

    /**
     * Signals that argue against the model's link to an existing task.
     *
     * @param  array{status: string, last_activity_at: string|null, people: list<string>, recent_activities: list<array{date: string, type: string, summary: string}>}  $task
     * @param  list<string>  $people  names the model attached to the item
     * @return list<string>
     */
    public function contradictions(array $task, array $people, CarbonImmutable $today): array
    {
        $found = [];

        if ($task['status'] === TaskStatus::Cancelled->value) {
            $found[] = 'task_cancelled';
        }

        $isActive = $task['status'] !== TaskStatus::Completed->value;

        if ($isActive && $task['last_activity_at'] !== null
            && CarbonImmutable::parse($task['last_activity_at'])->lt($today->subDays($this->staleDays))) {
            $found[] = 'stale_task';
        }

        if ($people !== [] && $task['people'] !== [] && ! $this->anyPersonKnown($people, $task)) {
            $found[] = 'person_mismatch';
        }

        return $found;
    }

    /**
     * @param  list<string>  $people
     * @param  array{people: list<string>, recent_activities: list<array{date: string, type: string, summary: string}>}  $task
     */
    private function anyPersonKnown(array $people, array $task): bool
    {
        $known = array_map('mb_strtolower', $task['people']);
        $history = mb_strtolower(implode(' ', array_column($task['recent_activities'], 'summary')));

        foreach ($people as $person) {
            $person = mb_strtolower(trim($person));

            if ($person !== '' && (in_array($person, $known, true) || str_contains($history, $person))) {
                return true;
            }
        }

        return false;
    }
}
