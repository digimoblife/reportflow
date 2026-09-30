<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Enums\ReportStatus;
use App\Enums\ReportType;
use App\Models\Project;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    public function definition(): array
    {
        $start = now()->startOfMonth()->subMonth();

        return [
            'project_id' => Project::factory(),
            'type' => ReportType::Monthly,
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'language' => Language::English,
            'template_id' => null,
            'status' => ReportStatus::Draft,
            'current_version_id' => null,
            'generation_lock_until' => null,
            'approved_at' => null,
        ];
    }
}
