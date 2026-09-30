<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate as FilamentAuthenticate;

class Authenticate extends FilamentAuthenticate
{
    /**
     * Handle unauthenticated user.
     *
     * In local environment, redirects to Filament login page.
     * When login is disabled (e.g. production before M5 Telegram Login Widget),
     * aborts with 404 instead of throwing 500 Route [login] not defined.
     */
    protected function unauthenticated($request, array $guards): void
    {
        $loginUrl = Filament::getLoginUrl();

        if (blank($loginUrl)) {
            abort(404);
        }

        parent::unauthenticated($request, $guards);
    }
}
