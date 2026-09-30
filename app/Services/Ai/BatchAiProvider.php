<?php

namespace App\Services\Ai;

/**
 * A provider that can send several independent requests at once (used by eval:run to cut wall-clock time).
 */
interface BatchAiProvider extends AiProvider
{
    /**
     * @param  list<AiRequest>  $requests
     * @return list<AiResponse|AiProviderException> one entry per request, same order; failures are returned, not thrown
     */
    public function completeMany(array $requests, int $concurrency): array;
}
