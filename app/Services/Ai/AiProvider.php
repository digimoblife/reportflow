<?php

namespace App\Services\Ai;

/**
 * The seam to an AI backend (CLAUDE.md: outgoing calls only through interfaces). Implementations:
 * DeepSeekProvider (real) and Fakes\FakeAiProvider (tests, eval). Input must already be redacted.
 */
interface AiProvider
{
    public function name(): string;

    /**
     * @throws AiProviderException on transport errors, HTTP errors and timeouts (never carries the input)
     */
    public function complete(AiRequest $request): AiResponse;
}
