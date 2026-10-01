<?php

namespace App\Models;

use App\Enums\ReportFileFormat;
use App\Models\Concerns\HasUserScope;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ReportFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @property int $id
 * @property int $report_version_id
 * @property ReportFileFormat $format
 * @property string $file_path
 * @property string $checksum
 * @property ReportVersion $reportVersion
 *
 * PRD §49 report_files. Files live on the private disk (PRD §56).
 */
#[Fillable(['report_version_id', 'format', 'file_path', 'checksum'])]
class ReportFile extends Model implements UserScoped
{
    /** @use HasFactory<ReportFileFactory> */
    use HasFactory;

    use HasUserScope;
    use StoresTimestampsWithOffset;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'format' => ReportFileFormat::class,
        ];
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->whereIn(
            $this->qualifyColumn('report_version_id'),
            fn (QueryBuilder $sub) => $sub->select('report_versions.id')->from('report_versions')
                ->join('reports', 'reports.id', '=', 'report_versions.report_id')
                ->join('projects', 'projects.id', '=', 'reports.project_id')
                ->where('projects.user_id', $userId),
        );
    }

    /**
     * @return BelongsTo<ReportVersion, $this>
     */
    public function reportVersion(): BelongsTo
    {
        return $this->belongsTo(ReportVersion::class);
    }
}
