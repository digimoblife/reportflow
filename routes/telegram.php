<?php

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\RequireSecureInProduction;
use App\Http\Middleware\VerifyTelegramSecret;
use Illuminate\Support\Facades\Route;

/*
| Telegram webhook (PRD §56). No `web` group: no sessions, no CSRF. The path comes from
| TELEGRAM_WEBHOOK_PATH and is only registered when it is long enough to be unguessable.
*/
$path = config('telegram.webhook_path');

if (is_string($path) && strlen($path) >= 32 && preg_match('/^[A-Za-z0-9_-]+$/', $path) === 1) {
    Route::post($path, TelegramWebhookController::class)
        // Secret first: unauthenticated traffic must not consume the rate-limit budget of real deliveries.
        ->middleware([RequireSecureInProduction::class, VerifyTelegramSecret::class, 'throttle:telegram-webhook'])
        ->name('telegram.webhook');
}
