<?php

namespace App\Services\Ai;

use SensitiveParameter;

final readonly class AiRequest
{
    public function __construct(
        public string $purpose,
        public string $promptVersion,
        #[SensitiveParameter] public string $input,
    ) {}
}
