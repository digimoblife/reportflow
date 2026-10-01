<?php

namespace App\Services\Worklog;

use App\Enums\InboundMessageStatus;
use App\Enums\MessageSource;
use App\Jobs\ProcessInboundMessage;
use App\Models\InboundMessage;
use App\Services\Redaction\RedactionService;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * Stores a note typed in the dashboard and queues it: the second front door of the single processing path (PRD §23,
 * CLAUDE.md rule 2). Same steps as the Telegram channel: redaction first (fail-closed, secrets never stored), the raw
 * (redacted) text kept, one idempotent row per submission key, then ProcessInboundMessage.
 */
class DashboardSubmission
{
    public const MAX_LENGTH = 8000;

    public function __construct(private readonly UserContext $context) {}

    /**
     * @param  string  $key  unique per form load; a double click or a network retry carries the same key
     */
    public function submit(#[SensitiveParameter] string $text, string $key): SubmissionResult
    {
        $userId = $this->context->requireUserId();
        $text = trim($text);

        if ($text === '') {
            return new SubmissionResult(SubmissionResult::EMPTY);
        }

        if (mb_strlen($text) > self::MAX_LENGTH || preg_match('/^[A-Za-z0-9-]{16,64}$/', $key) !== 1) {
            return new SubmissionResult(SubmissionResult::INVALID);
        }

        $redaction = RedactionService::forUser($userId)->redact($text);

        if ($redaction->failed()) {
            return new SubmissionResult(SubmissionResult::REDACTION_FAILED);
        }

        $now = Carbon::now('UTC');
        $idempotencyKey = "dashboard:{$userId}:{$key}";

        $inserted = InboundMessage::query()->insertOrIgnore([
            'user_id' => $userId,
            'source' => MessageSource::Dashboard->value,
            'idempotency_key' => $idempotencyKey,
            'text' => $redaction->text,
            'attachments' => '[]',
            'received_at' => $now,
            'status' => InboundMessageStatus::Received->value,
            'reprocess_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $message = InboundMessage::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

        // A duplicate delivery only re-dispatches a row that never got picked up (the job claims it atomically).
        if ($inserted === 1 || $message->status === InboundMessageStatus::Received) {
            ProcessInboundMessage::dispatch($message->id, $userId);
        }

        return new SubmissionResult($inserted === 1 ? SubmissionResult::QUEUED : SubmissionResult::DUPLICATE, $message->id, $redaction->secretCount());
    }
}
