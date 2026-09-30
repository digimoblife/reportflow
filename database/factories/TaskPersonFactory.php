<?php

namespace Database\Factories;

use App\Enums\TaskPersonRole;
use App\Models\Person;
use App\Models\Task;
use App\Models\TaskPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TaskPerson>
 */
class TaskPersonFactory extends Factory
{
    protected $model = TaskPerson::class;

    public function definition(): array
    {
        return [
            'task_id' => Task::factory(),
            'person_id' => Person::factory(),
            'role' => TaskPersonRole::Requester,
        ];
    }
}
