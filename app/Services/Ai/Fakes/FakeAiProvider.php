<?php

namespace App\Services\Ai\Fakes;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use Throwable;

/**
 * Deterministic AI stand-in. Records every request so tests can assert that only redacted text arrives.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiRequest> */
    public array $requests = [];

    private string $output = '{}';

    private ?Throwable $failure = null;

    private int $failuresLeft = 0;

    public function name(): string
    {
        return 'fake';
    }

    public function respondWith(string $output): self
    {
        $this->output = $output;

        return $this;
    }

    /**
     * Fail the next $times calls (default: every call from now on until reset).
     */
    public function failWith(?Throwable $failure = null, int $times = PHP_INT_MAX): self
    {
        $this->failure = $failure ?? new AiProviderException('fake provider failure');
        $this->failuresLeft = $times;

        return $this;
    }

    public function complete(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        if ($this->failure !== null && $this->failuresLeft > 0) {
            $this->failuresLeft--;

            throw $this->failure;
        }

        return new AiResponse($this->output, 'fake-model');
    }
}
