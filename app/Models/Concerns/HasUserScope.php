<?php

namespace App\Models\Concerns;

use App\Models\Scopes\UserScope;

/**
 * Registers the UserScope global scope. Models using it must implement UserScoped.
 */
trait HasUserScope
{
    public static function bootHasUserScope(): void
    {
        static::addGlobalScope(new UserScope);
    }
}
