<?php

namespace App\Services\Telegram;

use RuntimeException;

/**
 * Any failure talking to the Telegram Bot API.
 *
 * Deliberately built from scratch and thrown without `previous`: transport exceptions (Guzzle,
 * Laravel HTTP client) embed the request URL, and the URL contains the bot token.
 */
final class TelegramApiException extends RuntimeException
{
    private function __construct(
        public readonly string $apiMethod,
        public readonly ?int $httpStatus,
        public readonly string $description,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct(sprintf('Telegram %s failed (%s): %s', $apiMethod, $httpStatus ?? 'transport', $description));
    }

    public static function notConfigured(string $apiMethod): self
    {
        return new self($apiMethod, null, 'bot token is not configured');
    }

    public static function transport(string $apiMethod): self
    {
        return new self($apiMethod, null, 'connection failed or timed out');
    }

    public static function fromResponse(string $apiMethod, int $status, ?string $description, ?int $retryAfter = null): self
    {
        return new self($apiMethod, $status, mb_substr((string) $description, 0, 200), $retryAfter);
    }

    /** Worth retrying: rate limited, server error, or no answer at all. */
    public function isRetryable(): bool
    {
        return $this->httpStatus === null && $this->description !== 'bot token is not configured'
            || $this->httpStatus === 429
            || ($this->httpStatus !== null && $this->httpStatus >= 500);
    }

    /** 400/401/403/404: retrying the same request cannot succeed. */
    public function isPermanent(): bool
    {
        return ! $this->isRetryable();
    }

    public function messageNotModified(): bool
    {
        return $this->httpStatus === 400 && str_contains($this->description, 'message is not modified');
    }

    public function messageNotFound(): bool
    {
        return $this->httpStatus === 400 && (str_contains($this->description, 'message to edit not found') || str_contains($this->description, "message can't be edited"));
    }
}
