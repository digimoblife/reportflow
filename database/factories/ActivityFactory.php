<?php

namespace Database\Factories;

use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\DatePrecision;
use App\Models\Activity;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Activity>
 */
class ActivityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            // Must match the task's project (composite foreign key).
            'project_id' => fn (array $attributes) => Task::withoutGlobalScopes()->withTrashed()
                ->whereKey($attributes['task_id'])->value('project_id'),
            'inbound_message_id' => null,
            'activity_type' => ActivityType::Development,
            'summary' => fake()->sentence(),
            'content_structured' => [],
            'activity_date' => now()->toDateString(),
            'date_precision' => DatePrecision::Day,
            'source' => ActivitySource::Telegram,
        ];
    }
}
