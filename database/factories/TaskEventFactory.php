<?php

namespace Database\Factories;

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Models\Task;
use App\Models\TaskEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskEvent>
 */
class TaskEventFactory extends Factory
{
    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'event_type' => TaskEventType::Created,
            'from_value' => null,
            'to_value' => fn (array $attributes) => [
                ...Task::withoutGlobalScopes()->withTrashed()->whereKey($attributes['task_id'])->firstOrFail()
                    ->only(['project_id', 'title']),
                'status' => 'open',
                'waiting_reason' => null,
            ],
            'actor' => EventActor::Ai,
            'inbound_message_id' => null,
        ];
    }
}
