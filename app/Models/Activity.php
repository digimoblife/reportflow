<?php

namespace App\Models;

use App\Enums\ActivitySource;
use App\Enums\ActivityType;
use App\Enums\DatePrecision;
use App\Models\Concerns\ScopedThroughProject;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ActivityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int $project_id
 * @property ActivitySource $source
 * @property string $summary
 * @property ActivityType $activity_type
 * @property Carbon $activity_date
 *
 * PRD §15, §16, §49 activities. project_id always equals the task's project_id
 * (enforced by a composite foreign key that cascades when the task moves).
 */
#[Fillable([
    'task_id', 'project_id', 'inbound_message_id', 'activity_type', 'summary', 'content_structured',
    'activity_date', 'date_precision', 'source',
])]
class Activity extends Model implements UserScoped
{
    /** @use HasFactory<ActivityFactory> */
    use HasFactory;

    use ScopedThroughProject;
    use SoftDeletes;
    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'activity_type' => ActivityType::class,
            'content_structured' => 'array',
            'activity_date' => 'date',
            'date_precision' => DatePrecision::class,
            'source' => ActivitySource::class,
        ];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<InboundMessage, $this>
     */
    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class);
    }
}
