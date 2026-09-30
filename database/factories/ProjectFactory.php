<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'user_id' => $this->contextUser(),
            'name' => Str::title($name),
            'slug' => Str::slug($name),
            'aliases' => [],
            'description' => fake()->sentence(),
            'default_language' => null,
            'status' => ProjectStatus::Active,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => ProjectStatus::Archived]);
    }
}
