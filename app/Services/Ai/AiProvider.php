<?php

namespace App\Services\Ai;

/**
 * The seam to an AI backend. PROVISIONAL (M2): only enough for WorklogService to be wired;
 * M3 replaces it with the real AIService contract (schema-validated output, ai_interactions logging).
 * Input must already be redacted (CLAUDE.md rule 6).
 */
interface AiProvider
{
    public function name(): string;

    /**
     * @throws AiProviderException
     */
    public function complete(AiRequest $request): AiResponse;
}
