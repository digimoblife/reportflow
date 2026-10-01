<?php

namespace App\Models;

use App\Enums\ReminderState;
use App\Models\Concerns\HasUserScope;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ReminderInstanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $reminder_rule_id
 * @property ReminderState $status
 * @property Carbon|null $next_run_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $snoozed_until
 * @property int|null $telegram_message_id
 * @property string|null $action_taken
 * @property Carbon|null $reminder_date
 * @property int $send_count
 * @property int $snooze_count
 * @property ReminderRule $rule
 *
 * PRD §29, §49 reminder_instances.
 */
#[Fillable([
    'reminder_rule_id', 'task_id', 'next_run_at', 'status', 'sent_at', 'snoozed_until', 'telegram_message_id',
    'action_taken', 'reminder_date', 'send_count', 'snooze_count',
])]
class ReminderInstance extends Model implements UserScoped
{
    /** @use HasFactory<ReminderInstanceFactory> */
    use HasFactory;

    use HasUserScope;
    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'next_run_at' => 'datetime',
            'status' => ReminderState::class,
            'sent_at' => 'datetime',
            'snoozed_until' => 'datetime',
            'telegram_message_id' => 'integer',
            'reminder_date' => 'date:Y-m-d',
            'send_count' => 'integer',
            'snooze_count' => 'integer',
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->whereIn(
            $this->qualifyColumn('reminder_rule_id'),
            fn (QueryBuilder $sub) => $sub->select('id')->from('reminder_rules')->where('user_id', $userId),
        );
    }

    /**
     * @return BelongsTo<ReminderRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ReminderRule::class, 'reminder_rule_id');
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
