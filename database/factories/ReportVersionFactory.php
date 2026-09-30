<?php

namespace Database\Factories;

use App\Enums\MessageSource;
use App\Enums\ReportCreatedBy;
use App\Models\Report;
use App\Models\ReportVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportVersion>
 */
class ReportVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'report_id' => Report::factory(),
            'version_no' => 1,
            'content' => ['summary' => fake()->paragraph()],
            'data_snapshot_at' => now(),
            'source_activity_ids' => [],
            'created_by' => ReportCreatedBy::AiGenerate,
            'source_channel' => MessageSource::Dashboard,
            'instruction' => null,
            'version' => 1,
        ];
    }
}
