<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WaitingReason;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => rtrim(fake()->sentence(4), '.'),
            'description' => null,
            'type' => null,
            'status' => TaskStatus::Open,
            'waiting_reason' => null,
            'priority' => TaskPriority::Normal,
            'started_at' => null,
            'completed_at' => null,
            'last_activity_at' => null,
            'version' => 1,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Draft]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::InProgress, 'started_at' => now()->subDays(3)]);
    }

    public function waiting(WaitingReason $reason = WaitingReason::Client): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Waiting,
            'waiting_reason' => $reason,
            'started_at' => now()->subDays(5),
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Blocked, 'started_at' => now()->subDays(5)]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => TaskStatus::Completed,
            'started_at' => now()->subDays(10),
            'completed_at' => now()->subDay(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => TaskStatus::Cancelled]);
    }
}
