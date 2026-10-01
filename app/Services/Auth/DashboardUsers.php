<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\UserContext;

/**
 * Finds the account a dashboard sign-in belongs to. The users table is the whitelist (PRD §56); `users` is the scope
 * itself, so these lookups run outside any user context.
 */
class DashboardUsers
{
    public function __construct(private readonly UserContext $context) {}

    public function byTelegramId(int $telegramId): ?User
    {
        return $this->context->runAsSystem(fn () => User::query()->where('telegram_user_id', $telegramId)->first());
    }

    public function byId(int $id): ?User
    {
        return $this->context->runAsSystem(fn () => User::query()->find($id));
    }
}
