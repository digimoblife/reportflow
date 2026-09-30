<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accepts only requests carrying the secret registered with setWebhook (PRD §56).
 * Constant-time comparison; a missing or malformed configured secret rejects everything.
 * Every rejection is a bare 404 so the endpoint reveals nothing.
 */
class VerifyTelegramSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('telegram.secret_token');
        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (! is_string($expected) || $expected === '' || ! is_string($given) || ! hash_equals($expected, $given)) {
            abort(404);
        }

        return $next($request);
    }
}
