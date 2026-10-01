<?php

namespace App\Services\Report;

use App\Enums\Language;
use App\Models\ReportTemplate;
use Illuminate\Support\Facades\Lang;

/**
 * The generic report template of a user per language (PRD §40), created on first use from config/reports.php and the
 * report lang files. Sections, their order and titles are data, not code. Needs a UserContext.
 */
class ReportTemplates
{
    public function forLanguage(Language $language): ReportTemplate
    {
        return ReportTemplate::query()->firstOrCreate(
            ['project_id' => null, 'language' => $language, 'blade_view' => (string) config('reports.blade_view')],
            [
                'name' => (string) Lang::get('report.generic_template', [], $language->value),
                'title_format' => (string) Lang::get('report.title', [], $language->value),
                'sections' => $this->sections($language),
                'formatting_rules' => [],
            ],
        );
    }

    /**
     * @return list<array{key: string, title: string, narrative: bool}>
     */
    public function sections(Language $language): array
    {
        $sections = [];

        foreach ((array) config('reports.sections') as $section) {
            $sections[] = [
                'key' => (string) $section['key'],
                'title' => (string) Lang::get('report.sections.'.$section['key'], [], $language->value),
                'narrative' => (bool) $section['narrative'],
            ];
        }

        return $sections;
    }
}
