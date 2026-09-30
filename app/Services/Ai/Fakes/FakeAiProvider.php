<?php

namespace App\Services\Ai\Fakes;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use Closure;
use Throwable;

/**
 * Deterministic AI stand-in for tests and the eval harness. Records every request.
 *
 * Answer order: queued outputs first, then the responder closure, then the default (a valid
 * "nothing to record" extraction, so pipelines that do not care about the AI stay green).
 */
final class FakeAiProvider implements AiProvider
{
    public const EMPTY_EXTRACTION = '{"items":[],"clarification_needed":null}';

    /** @var list<AiRequest> */
    public array $requests = [];

    private string $output = self::EMPTY_EXTRACTION;

    /** @var list<string> */
    private array $queue = [];

    /** @var (Closure(AiRequest): string)|null */
    private ?Closure $responder = null;

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
     * Outputs returned one per call, before any other answer.
     */
    public function queue(string ...$outputs): self
    {
        array_push($this->queue, ...$outputs);

        return $this;
    }

    /**
     * @param  Closure(AiRequest): string  $responder
     */
    public function using(Closure $responder): self
    {
        $this->responder = $responder;

        return $this;
    }

    /**
     * Fail the next $times calls (default: every call from now on).
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

        $content = $this->queue !== []
            ? array_shift($this->queue)
            : ($this->responder !== null ? ($this->responder)($request) : $this->output);

        return new AiResponse($content, 'fake-model', tokensInput: 100, tokensOutput: 50, latencyMs: 1);
    }
}
