<?php

namespace App\Models;

use App\Enums\EventActor;
use App\Enums\TaskEventType;
use App\Models\Concerns\HasUserScope;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\TaskEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * PRD §47, §49 task_events: append-only audit trail. from_value / to_value shapes per
 * event_type are documented in docs/DECISIONS.md so undo (M4) can restore state exactly.
 */
#[Fillable(['task_id', 'event_type', 'from_value', 'to_value', 'actor', 'inbound_message_id'])]
class TaskEvent extends Model implements UserScoped
{
    /** @use HasFactory<TaskEventFactory> */
    use HasFactory;

    use HasUserScope;
    use StoresTimestampsWithOffset;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_type' => TaskEventType::class,
            'from_value' => 'array',
            'to_value' => 'array',
            'actor' => EventActor::class,
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
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /**
     * @return BelongsTo<InboundMessage, $this>
     */
    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class);
    }
}
