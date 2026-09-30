<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * PRD §49 people: requesters, assignees and stakeholders mentioned in worklogs.
 */
#[Fillable(['user_id', 'name', 'aliases', 'notes'])]
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
