<?php

namespace App\Services\Ai;

use SensitiveParameter;

/**
 * One chat-style call. `user` carries the (already redacted) message and candidate data.
 */
final readonly class AiRequest
{
    public function __construct(
        public string $purpose,
        public string $promptVersion,
        public string $system,
        #[SensitiveParameter] public string $user,
        public bool $jsonMode = true,
    ) {}
}
