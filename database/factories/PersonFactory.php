<?php

namespace Database\Factories;

use App\Models\Person;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Person>
 */
class PersonFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return [
            'user_id' => $this->contextUser(),
            'name' => fake()->firstName(),
            'aliases' => [],
            'notes' => null,
        ];
    }
}
