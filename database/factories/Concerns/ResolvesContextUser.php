<?php

namespace Database\Factories\Concerns;

use App\Models\User;
use App\Support\UserContext;
use Closure;
use Illuminate\Database\Eloquent\Factories\Factory;

trait ResolvesContextUser
{
    /**
     * The acting user when a UserContext is set, otherwise a new user.
     *
     * @return Closure(): (int|Factory<User>)
     */
    protected function contextUser(): Closure
    {
        return fn () => app(UserContext::class)->userId() ?? User::factory();
    }
}
