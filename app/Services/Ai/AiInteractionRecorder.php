<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;

/**
 * Writes ai_interactions rows (PRD §49, §79). `input` must be post-redaction; `error` is a code, never text.
 * Needs a UserContext (the model fills user_id from it).
 */
class AiInteractionRecorder
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function record(
        string $purpose,
        string $model,
        string $promptVersion,
        array $input,
        ?string $output,
        bool $success,
        ?string $error = null,
        ?int $tokensInput = null,
        ?int $tokensOutput = null,
        ?int $latencyMs = null,
        ?int $inboundMessageId = null,
        ?int $projectId = null,
    ): AiInteraction {
        return AiInteraction::query()->create([
            'project_id' => $projectId,
            'inbound_message_id' => $inboundMessageId,
            'purpose' => $purpose,
            'model' => $model,
            'prompt_version' => $promptVersion,
            'input' => $input,
            'output' => $output,
            'tokens_input' => $tokensInput,
            'tokens_output' => $tokensOutput,
            'latency_ms' => $latencyMs,
            'success' => $success,
            'error' => $error === null ? null : mb_substr($error, 0, 500),
        ]);
    }
}
