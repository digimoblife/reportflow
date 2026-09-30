<?php

namespace App\Services\Ai;

final readonly class AiResponse
{
    public function __construct(
        public string $output,
        public string $model,
    ) {}
}
