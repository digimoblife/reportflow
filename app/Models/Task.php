<?php

namespace App\Models;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\WaitingReason;
use App\Models\Concerns\ScopedThroughProject;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property TaskStatus $status
 * @property WaitingReason|null $waiting_reason
 * @property Carbon|null $started_at
 * @property int $version
 * @property Carbon|null $completed_at
 * @property Carbon|null $last_activity_at
 *
 * PRD §14, §49 tasks. Status changes must go through TaskStatusTransition and be recorded
 * in task_events (M4). `version` is reserved for optimistic locking (PRD §23, mechanism in M4).
 */
#[Fillable([
    'project_id', 'title', 'description', 'type', 'status', 'waiting_reason', 'priority',
    'started_at', 'completed_at', 'last_activity_at', 'version',
])]
class Task extends Model implements UserScoped
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use ScopedThroughProject;
    use SoftDeletes;
    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'waiting_reason' => WaitingReason::class,
            'priority' => TaskPriority::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'version' => 'integer',
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
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<TaskEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    /**
     * @return BelongsToMany<Person, $this, TaskPerson, 'assignment'>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'task_people')
            ->using(TaskPerson::class)
            ->as('assignment')
            ->withPivot('id', 'role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<TaskPerson, $this>
     */
    public function taskPeople(): HasMany
    {
        return $this->hasMany(TaskPerson::class);
    }

    /**
     * @return HasMany<ReminderInstance, $this>
     */
    public function reminderInstances(): HasMany
    {
        return $this->hasMany(ReminderInstance::class);
    }
}
