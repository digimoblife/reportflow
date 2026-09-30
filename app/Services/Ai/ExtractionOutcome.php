<?php

namespace App\Services\Ai;

final readonly class ExtractionOutcome
{
    /**
     * @param  array<string, mixed>  $data  schema-valid model output
     */
    public function __construct(
        public array $data,
        public string $promptVersion,
        public int $attempts,
        public int $tokensInput,
        public int $tokensOutput,
    ) {}
}
