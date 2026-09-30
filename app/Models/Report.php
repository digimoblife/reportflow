<?php

namespace App\Models;

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Concerns\ScopedThroughProject;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §36–§44, §49 reports.
 */
#[Fillable([
    'project_id', 'type', 'period_start', 'period_end', 'language', 'template_id', 'status',
    'current_version_id', 'generation_lock_until', 'approved_at',
])]
class Report extends Model implements UserScoped
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    use ScopedThroughProject;
    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'type' => ReportType::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'language' => Language::class,
            'status' => ReportStatus::class,
            'generation_lock_until' => 'datetime',
            'approved_at' => 'datetime',
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
     * @return BelongsTo<ReportTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReportTemplate::class, 'template_id');
    }

    /**
     * @return HasMany<ReportVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(ReportVersion::class);
    }

    /**
     * @return BelongsTo<ReportVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ReportVersion::class, 'current_version_id');
    }
}
