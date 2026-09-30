<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * An AI backend failed. Messages must never contain the request input.
 */
class AiProviderException extends RuntimeException {}
