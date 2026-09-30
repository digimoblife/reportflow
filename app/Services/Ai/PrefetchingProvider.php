<?php

namespace App\Services\Ai;

/**
 * Wraps a batch-capable provider: answers many requests concurrently up front, then hands each answer out
 * once when the normal, sequential pipeline asks for the identical request. Anything not prefetched (for
 * example the retry after an invalid reply) goes to the real provider as usual.
 */
final class PrefetchingProvider implements AiProvider
{
    /** @var array<string, AiResponse|AiProviderException> */
    private array $prefetched = [];

    public function __construct(private readonly BatchAiProvider $inner) {}

    public function name(): string
    {
        return $this->inner->name();
    }

    /**
     * @param  list<AiRequest>  $requests
     */
    public function prefetch(array $requests, int $concurrency): void
    {
        foreach ($this->inner->completeMany($requests, $concurrency) as $i => $result) {
            $this->prefetched[$this->key($requests[$i])] = $result;
        }
    }

    public function complete(AiRequest $request): AiResponse
    {
        $key = $this->key($request);

        if (isset($this->prefetched[$key])) {
            $result = $this->prefetched[$key];
            unset($this->prefetched[$key]); // single use: a retry must reach the real provider

            if ($result instanceof AiProviderException) {
                throw $result;
            }

            return $result;
        }

        return $this->inner->complete($request);
    }

    private function key(AiRequest $request): string
    {
        return hash('sha256', $request->system."\0".$request->user."\0".($request->jsonMode ? '1' : '0'));
    }
}
