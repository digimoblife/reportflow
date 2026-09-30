<?php

namespace App\Models\Scopes;

use App\Models\Contracts\UserScoped;
use App\Support\UserContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts every query on a UserScoped model to the current UserContext user.
 * Fails closed (throws) when no user is set, unless running as system.
 *
 * @implements Scope<Model>
 */
class UserScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(UserContext::class);

        if ($context->isSystem()) {
            return;
        }

        if (! $model instanceof UserScoped) {
            return;
        }

        $model->applyUserScope($builder, $context->requireUserId());
    }
}
