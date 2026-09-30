<?php

namespace Database\Factories;

use App\Models\AiInteraction;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiInteraction>
 */
class AiInteractionFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return [
            'user_id' => $this->contextUser(),
            'project_id' => null,
            'inbound_message_id' => null,
            'report_id' => null,
            'purpose' => 'worklog_extraction',
            'model' => 'fake-model',
            'prompt_version' => 'worklog_extraction/v1',
            'input' => ['messages' => [['role' => 'user', 'content' => 'Configured the staging server.']]],
            'output' => '{"items":[]}',
            'tokens_input' => 120,
            'tokens_output' => 30,
            'latency_ms' => 850,
            'success' => true,
            'error' => null,
        ];
    }
}
