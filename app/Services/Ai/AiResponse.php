<?php

namespace App\Services\Ai;

final readonly class AiResponse
{
    public function __construct(
        public string $content,
        public string $model,
        public ?int $tokensInput = null,
        public ?int $tokensOutput = null,
        public ?int $latencyMs = null,
    ) {}
}
