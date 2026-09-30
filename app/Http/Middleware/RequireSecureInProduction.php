<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The webhook is HTTPS-only in production (PRD §56). isSecure() honours X-Forwarded-Proto only from
 * proxies listed in TRUSTED_PROXIES (see bootstrap/app.php).
 */
class RequireSecureInProduction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->isProduction() && ! $request->isSecure()) {
            abort(404);
        }

        return $next($request);
    }
}
