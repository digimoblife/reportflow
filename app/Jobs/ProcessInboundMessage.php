<?php

namespace App\Jobs;

use App\Enums\InboundMessageStatus;
use App\Jobs\Middleware\WithUserContext;
use App\Models\InboundMessage;
use App\Services\Worklog\WorklogService;
use App\Support\UserContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processes one stored inbound message (PRD §7, §48, §58). Idempotent and safe to retry:
 *
 * - The row is claimed atomically (received -> processing), so duplicate dispatches do nothing.
 *   A retry after an exception may re-claim a row still in `processing`.
 * - Messages of one user are serialised (WithoutOverlapping); a job that meets the lock is released,
 *   which never counts as a failure.
 * - Retries are bounded by time (retryUntil) and by real exceptions (maxExceptions), not by attempts.
 * - Delivering the confirmation is a separate job, so a Telegram outage can neither fail an already
 *   processed message nor cause it to be processed again.
 *
 * The job carries ids only; the message text stays in the database.
 */
class ProcessInboundMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 3;

    public int $timeout = 90;

    public function __construct(
        public readonly int $inboundMessageId,
        public readonly int $userId,
    ) {
        $this->onQueue('default');
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            new WithUserContext($this->userId),
            (new WithoutOverlapping('inbound-user:'.$this->userId))->releaseAfter(5)->expireAfter(120),
        ];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [5, 30, 120];
    }

    public function handle(WorklogService $worklog): void
    {
        $claimable = $this->attempts() > 1
            ? [InboundMessageStatus::Received->value, InboundMessageStatus::Processing->value]
            : [InboundMessageStatus::Received->value];

        $claimed = InboundMessage::query()
            ->whereKey($this->inboundMessageId)
            ->whereIn('status', $claimable)
            ->update(['status' => InboundMessageStatus::Processing->value]);

        if ($claimed === 0) {
            return; // already processed/failed, still being handled, or gone
        }

        $message = InboundMessage::query()->findOrFail($this->inboundMessageId);

        $result = $worklog->process($message);

        InboundMessage::query()->whereKey($message->id)->update([
            'status' => ($result->outcome->hasPending()) ? InboundMessageStatus::NeedsClarification->value : InboundMessageStatus::Processed->value,
            'error' => null,
        ]);

        DeliverInboundConfirmation::dispatch($message->id, $this->userId, DeliverInboundConfirmation::PROCESSED);
    }

    /**
     * Retries exhausted. Runs outside the job middleware, so the user context is set here.
     * The error column holds a code and a class name only, never message text (PRD §58, rule 6).
     */
    public function failed(Throwable $e): void
    {
        app(UserContext::class)->runAs($this->userId, function () use ($e): void {
            $updated = InboundMessage::query()
                ->whereKey($this->inboundMessageId)
                ->whereIn('status', [InboundMessageStatus::Received->value, InboundMessageStatus::Processing->value])
                ->update([
                    'status' => InboundMessageStatus::Failed->value,
                    'error' => 'worklog_failed:'.class_basename($e),
                ]);

            Log::error('inbound.processing_failed', ['inbound_message_id' => $this->inboundMessageId, 'exception' => $e::class]);

            if ($updated > 0) {
                DeliverInboundConfirmation::dispatch($this->inboundMessageId, $this->userId, DeliverInboundConfirmation::FAILED);
            }
        });
    }
}
