<?php

namespace App\Models\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A model whose rows belong to exactly one user, directly or through a parent (CLAUDE.md rule 11).
 */
interface UserScoped
{
    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void;
}
