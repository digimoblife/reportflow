<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\DashboardUsers;
use App\Services\Auth\LoginLinkService;
use App\Services\Auth\TelegramLoginVerifier;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard sign-in (PRD §22, §56): Telegram Login Widget callback and one-time login links. Both end in the same
 * place: the Telegram user id must belong to a registered user (the whitelist). Every failure is the same bare 403,
 * so the response never tells an attacker which check failed.
 */
class DashboardLoginController extends Controller
{
    public function telegram(Request $request, TelegramLoginVerifier $verifier, DashboardUsers $directory): RedirectResponse
    {
        $telegramId = $verifier->verify($request->query());

        return $this->signIn($request, $telegramId === null ? null : $directory->byTelegramId($telegramId));
    }

    public function link(Request $request, string $token, LoginLinkService $links, DashboardUsers $directory): RedirectResponse
    {
        $userId = $links->consume($token);

        return $this->signIn($request, $userId === null ? null : $directory->byId($userId));
    }

    private function signIn(Request $request, ?Authenticatable $user): RedirectResponse
    {
        if ($user === null) {
            abort(403);
        }

        Auth::guard(Filament::getAuthGuard())->login($user);
        $request->session()->regenerate();

        return redirect()->intended(Filament::getUrl());
    }
}
