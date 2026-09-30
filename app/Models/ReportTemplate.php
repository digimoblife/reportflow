<?php

namespace App\Models;

use App\Enums\Language;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\ReportTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PRD §40, §49 report_templates. project_id null means a global template of the user.
 */
#[Fillable([
    'user_id', 'project_id', 'name', 'language', 'title_format', 'sections', 'blade_view', 'stylesheet',
    'formatting_rules',
])]
class ReportTemplate extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<ReportTemplateFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'language' => Language::class,
            'sections' => 'array',
            'formatting_rules' => 'array',
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
     * @return HasMany<Report, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Report::class, 'template_id');
    }
}
