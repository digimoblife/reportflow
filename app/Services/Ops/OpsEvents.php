<?php

namespace App\Services\Ops;

use App\Models\SystemEvent;
use App\Support\UserContext;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records operational events (PRD §78). The context may only hold small codes and counters: never message text, titles,
 * summaries or secrets. Recording must never break the work that reported it, so a failure here is swallowed (and logged
 * as a bare code).
 */
class OpsEvents
{
    public const TELEGRAM_FAILED = 'telegram_failed';

    public const REPORT_FAILED = 'report_generation_failed';

    public const PDF_FAILED = 'pdf_failed';

    public const PURGE = 'purge';

    public const ALERT = 'alert';

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function record(string $type, array $context = []): void
    {
        try {
            $user = app(UserContext::class)->userId();

            SystemEvent::query()->create(['user_id' => $user, 'type' => $type, 'context' => $context]);
        } catch (Throwable $e) {
            Log::warning('ops.event_not_recorded', ['type' => $type, 'exception' => $e::class]);
        }
    }
}
