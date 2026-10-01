<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Auth\LoginLinkService;
use App\Support\UserContext;
use Illuminate\Console\Command;

/**
 * Prints a one-time dashboard login link (M5). Development and emergency access only: refused in production,
 * where the Telegram Login Widget is the way in (PRD §56).
 */
class LoginLink extends Command
{
    protected $signature = 'reportflow:login-link {telegram_id : Telegram user id of a registered user}';

    protected $description = 'Print a one-time dashboard login link (not available in production)';

    public function handle(LoginLinkService $links, UserContext $context): int
    {
        if (app()->isProduction()) {
            $this->error('Login links are disabled in production.');

            return self::FAILURE;
        }

        $user = $context->runAsSystem(fn () => User::query()->where('telegram_user_id', (int) $this->argument('telegram_id'))->first());

        if ($user === null) {
            $this->error('No registered user has that Telegram id.');

            return self::FAILURE;
        }

        $this->line(url('/auth/link/'.$links->issue($user->id)));
        $this->info('Valid for '.(LoginLinkService::TTL_SECONDS / 60).' minutes, works once.');

        return self::SUCCESS;
    }
}
