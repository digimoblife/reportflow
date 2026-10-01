<?php

namespace Database\Factories;

use App\Models\SystemEvent;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SystemEvent>
 */
class SystemEventFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return ['user_id' => $this->contextUser(), 'type' => 'telegram_failed', 'context' => ['status' => 500]];
    }
}
