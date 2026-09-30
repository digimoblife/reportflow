<?php

namespace App\Models;

use App\Enums\MessageSource;
use App\Enums\ReportCreatedBy;
use App\Models\Concerns\HasUserScope;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ReportVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * PRD §23, §43, §44, §49 report_versions. Approved versions are immutable (enforced in M7).
 */
#[Fillable([
    'report_id', 'version_no', 'content', 'data_snapshot_at', 'source_activity_ids', 'created_by',
    'source_channel', 'instruction', 'version',
])]
class ReportVersion extends Model implements UserScoped
{
    /** @use HasFactory<ReportVersionFactory> */
    use HasFactory;

    use HasUserScope;
    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'content' => 'array',
            'data_snapshot_at' => 'datetime',
            'source_activity_ids' => 'array',
            'created_by' => ReportCreatedBy::class,
            'source_channel' => MessageSource::class,
            'version' => 'integer',
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->whereIn(
            $this->qualifyColumn('report_id'),
            fn (QueryBuilder $sub) => $sub->select('reports.id')->from('reports')
                ->join('projects', 'projects.id', '=', 'reports.project_id')
                ->where('projects.user_id', $userId),
        );
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return HasMany<ReportFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ReportFile::class);
    }
}
