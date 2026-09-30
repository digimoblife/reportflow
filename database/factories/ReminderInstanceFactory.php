<?php

namespace Database\Factories;

use App\Enums\ReminderState;
use App\Models\ReminderInstance;
use App\Models\ReminderRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderInstance>
 */
class ReminderInstanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reminder_rule_id' => ReminderRule::factory(),
            'task_id' => null,
            'next_run_at' => now()->addDay(),
            'status' => ReminderState::Scheduled,
            'sent_at' => null,
            'snoozed_until' => null,
            'telegram_message_id' => null,
            'action_taken' => null,
        ];
    }
}
