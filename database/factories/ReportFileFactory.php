<?php

namespace Database\Factories;

use App\Enums\ReportFileFormat;
use App\Models\ReportFile;
use App\Models\ReportVersion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReportFile>
 */
class ReportFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'report_version_id' => ReportVersion::factory(),
            'format' => ReportFileFormat::Pdf,
            'file_path' => 'reports/'.Str::uuid().'.pdf',
            'checksum' => hash('sha256', Str::random(32)),
        ];
    }
}
