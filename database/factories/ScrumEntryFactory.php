<?php

namespace Database\Factories;

use App\Models\Person;
use App\Models\Project;
use App\Models\ScrumEntry;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScrumEntry>
 */
class ScrumEntryFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return [
            'user_id' => $this->contextUser(),
            'project_id' => Project::factory(),
            'person_id' => Person::factory(),
            'scrum_date' => now()->toDateString(),
            'what_have_done' => fake()->sentence(),
            'issue' => null,
            'what_to_do_next' => fake()->sentence(),
        ];
    }
}
