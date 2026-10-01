<?php

namespace App\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Pages\SimplePage;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Dashboard sign-in page (PRD §22): the Telegram Login Widget, nothing else. There is no password.
 */
class TelegramLogin extends SimplePage
{
    protected string $view = 'filament.pages.telegram-login';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(Filament::getUrl());
        }
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('ui.dashboard.login.title');
    }

    public function getHeading(): string|Htmlable
    {
        return (string) __('ui.dashboard.login.heading');
    }

    public function botUsername(): ?string
    {
        $username = config('telegram.bot_username');

        return is_string($username) && preg_match('/^[A-Za-z0-9_]{5,32}$/', $username) === 1 ? $username : null;
    }
}
