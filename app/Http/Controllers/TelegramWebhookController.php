<?php

namespace App\Http\Controllers;

use App\Services\Telegram\TelegramIngestionService;
use App\Services\Telegram\TelegramUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Telegram webhook (PRD §7, §18). Thin: parse, hand to the ingestion service, answer 200.
 *
 * Retry policy: Telegram re-sends until it gets a 2xx.
 * - Nothing to fix by retrying (unsupported update type, unregistered sender, malformed payload): 200.
 * - Transient failure before the message is stored (database down): 500 so Telegram retries, but at
 *   most MAX_FAILURES times per update, then 200 so a poison update cannot loop forever.
 */
class TelegramWebhookController extends Controller
{
    private const MAX_FAILURES = 3;

    public function __invoke(Request $request, TelegramIngestionService $ingestion): JsonResponse
    {
        $payload = $request->json()->all();
        $update = TelegramUpdate::fromArray($payload);

        if ($update === null) {
            return response()->json(['ok' => true]);
        }

        try {
            $ingestion->handle($update);
        } catch (Throwable $e) {
            // Class only: the message of an exception can carry SQL bindings, i.e. user text.
            Log::error('telegram.webhook_failed', ['update_id' => $update->updateId, 'exception' => $e::class]);

            $key = "tg:failures:{$update->updateId}";
            Cache::add($key, 0, 3600);

            if (Cache::increment($key) < self::MAX_FAILURES) {
                return response()->json(['ok' => false], 500);
            }
        }

        return response()->json(['ok' => true]);
    }
}
