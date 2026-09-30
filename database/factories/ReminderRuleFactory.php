<?php

namespace Database\Factories;

use App\Enums\ReminderPriority;
use App\Enums\ReminderType;
use App\Models\ReminderRule;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderRule>
 */
class ReminderRuleFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return [
            'user_id' => $this->contextUser(),
            'project_id' => null,
            'type' => ReminderType::DailyWorklog,
            'schedule' => ['time' => '18:00', 'days' => ['mon', 'tue', 'wed', 'thu', 'fri']],
            'config' => [],
            'priority' => ReminderPriority::Normal,
            'enabled' => true,
        ];
    }

    public function monthlyReport(): static
    {
        return $this->state(fn () => [
            'type' => ReminderType::MonthlyReport,
            'schedule' => ['day' => 'last', 'time' => '09:00'],
        ]);
    }
}
