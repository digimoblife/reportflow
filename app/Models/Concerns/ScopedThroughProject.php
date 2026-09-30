<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * For models that have a project_id but no user_id (tasks, activities, reports):
 * rows are visible only when their project belongs to the current user.
 */
trait ScopedThroughProject
{
    use HasUserScope;

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->whereIn(
            $this->qualifyColumn('project_id'),
            fn (QueryBuilder $sub) => $sub->select('id')->from('projects')->where('user_id', $userId),
        );
    }
}
