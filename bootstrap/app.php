<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::group([], base_path('routes/telegram.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only these proxies may set X-Forwarded-*; empty (default) trusts none. Never a wildcard.
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));

        foreach ($proxies as $proxy) {
            if (str_contains($proxy, '*') || in_array($proxy, ['0.0.0.0/0', '::/0'], true)) {
                throw new RuntimeException('TRUSTED_PROXIES must list specific proxies or CIDR ranges, not a wildcard.');
            }
        }

        // Production answers only for its own host (APP_URL); a forged Host header gets a 400 instead of reaching the app.
        if (env('APP_ENV') === 'production') {
            $middleware->trustHosts(at: function (): array {
                $host = parse_url((string) env('APP_URL'), PHP_URL_HOST);

                return is_string($host) && $host !== '' ? ['^'.preg_quote($host, '/').'$'] : [];
            }, subdomains: false);
        }

        $middleware->trustProxies(at: $proxies, headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
