<?php

namespace App\Models;

use App\Enums\ReminderPriority;
use App\Enums\ReminderType;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ReminderRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property ReminderType $type
 * @property array<string, mixed> $schedule
 * @property array<string, mixed> $config
 * @property bool $enabled
 *
 * PRD §28, §32, §49 reminder_rules. project_id null = global rule.
 */
#[Fillable(['user_id', 'project_id', 'type', 'schedule', 'config', 'priority', 'enabled'])]
class ReminderRule extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<ReminderRuleFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'type' => ReminderType::class,
            'schedule' => 'array',
            'config' => 'array',
            'priority' => ReminderPriority::class,
            'enabled' => 'boolean',
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
     * @return HasMany<ReminderInstance, $this>
     */
    public function instances(): HasMany
    {
        return $this->hasMany(ReminderInstance::class);
    }
}
