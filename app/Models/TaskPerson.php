<?php

namespace App\Models;

use App\Enums\TaskPersonRole;
use App\Models\Concerns\HasUserScope;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\TaskPersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * PRD §49 task_people pivot (role: requester / assignee / stakeholder).
 */
#[Fillable(['task_id', 'person_id', 'role'])]
class TaskPerson extends Pivot implements UserScoped
{
    /** @use HasFactory<TaskPersonFactory> */
    use HasFactory;

    use HasUserScope;
    use StoresTimestampsWithOffset;

    protected $table = 'task_people';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => TaskPersonRole::class,
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->whereIn(
            $this->qualifyColumn('task_id'),
            fn (QueryBuilder $sub) => $sub->select('tasks.id')->from('tasks')
                ->join('projects', 'projects.id', '=', 'tasks.project_id')
                ->where('projects.user_id', $userId),
        );
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
