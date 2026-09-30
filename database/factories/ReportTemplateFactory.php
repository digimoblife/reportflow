<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\ReportTemplate;
use Database\Factories\Concerns\ResolvesContextUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    use ResolvesContextUser;

    public function definition(): array
    {
        return [
            'user_id' => $this->contextUser(),
            'project_id' => null,
            'name' => 'Generic Monthly Report',
            'language' => Language::English,
            'title_format' => '{project} Monthly Report — {period}',
            'sections' => [
                ['key' => 'summary', 'title' => 'Summary'],
                ['key' => 'completed', 'title' => 'Completed Work'],
                ['key' => 'ongoing', 'title' => 'Ongoing Work'],
            ],
            'blade_view' => 'reports.templates.generic',
            'stylesheet' => null,
            'formatting_rules' => [],
        ];
    }
}
