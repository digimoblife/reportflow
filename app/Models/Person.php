<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int|null $project_id
 * @property string $name
 * @property string|null $role_title
 * @property array<int, mixed>|null $aliases
 *
 * PRD §49 people: requesters, assignees and stakeholders mentioned in worklogs.
 */
#[Fillable(['user_id', 'project_id', 'name', 'role_title', 'aliases', 'notes'])]
class Person extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<PersonFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany<Task, $this, TaskPerson, 'assignment'>
     */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_people')
            ->using(TaskPerson::class)
            ->as('assignment')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }
}
