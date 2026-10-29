<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Carbon\CarbonImmutable;
use Database\Factories\ScrumEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $project_id
 * @property int $person_id
 * @property CarbonImmutable $scrum_date
 * @property string|null $what_have_done
 * @property string|null $issue
 * @property string|null $what_to_do_next
 *
 * PRD Scrum: recorded daily standup details per person and project.
 */
#[Fillable([
    'user_id',
    'project_id',
    'person_id',
    'scrum_date',
    'what_have_done',
    'issue',
    'what_to_do_next',
])]
class ScrumEntry extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<ScrumEntryFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'scrum_date' => 'date',
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
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
