<?php

namespace Database\Factories;

use App\Enums\CorrectionType;
use App\Models\Correction;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Correction>
 */
class CorrectionFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return [
            'user_id' => $this->contextUser(),
            'inbound_message_id' => null,
            'correction_type' => CorrectionType::ChangeStatus,
            'before' => ['status' => 'completed', 'waiting_reason' => null],
            'after' => ['status' => 'in_progress', 'waiting_reason' => null],
        ];
    }
}
