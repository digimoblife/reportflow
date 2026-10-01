<?php

namespace App\Services\Report;

use App\Enums\Language;
use App\Enums\ReportType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * The deterministic part of every report section (decision for M7: numbers, lists and tables come from the snapshot,
 * never from the model). Output is Markdown in the report's language, formal and neutral. Text that came from a
 * person (task titles, activity summaries) is escaped, so a title can never change the structure of the document.
 */
class ReportFactsBuilder
{
    public function periodLabel(ReportType $type, string $start, string $end, Language $language): string
    {
        $from = $this->at($start, $language);
        $to = $this->at($end, $language);

        if ($type === ReportType::Monthly && $from->day === 1 && $to->format('Y-m-d') === $from->endOfMonth()->format('Y-m-d')) {
            return $from->translatedFormat('F Y');
        }

        return $from->translatedFormat('j M Y').' – '.$to->translatedFormat('j M Y');
    }

    public function title(ReportDataSet $data, ReportType $type, Language $language): string
    {
        $key = $type === ReportType::Monthly ? 'report.title' : 'report.title_custom';

        return (string) Lang::get($key, ['project' => $data->projectName, 'period' => $this->periodLabel($type, $data->periodStart, $data->periodEnd, $language)], $language->value);
    }

    /**
     * Markdown of one section's facts (no heading; the template adds it).
     */
    public function facts(string $section, ReportDataSet $data, ReportType $type, Language $language): string
    {
        return match ($section) {
            'overview' => $this->overview($data, $type, $language),
            'completed' => $this->completed($data, $language),
            'detailed' => $this->detailed($data, $language),
            'ongoing' => $this->ongoing($data, $language),
            'cross_month' => $this->crossMonth($data, $language),
            'incidents' => $this->incidents($data, $language),
            default => '',
        };
    }

    /**
     * What stands in for the AI narrative when none was written or the backend rejected it. Numbers from the data only.
     */
    public function fallbackNarrative(string $section, ReportDataSet $data, ReportType $type, Language $language): string
    {
        $counts = $data->counts();

        if (! Lang::has('report.fallback.'.$section, $language->value)) {
            return '';
        }

        return (string) Lang::get('report.fallback.'.$section, [
            'project' => $data->projectName,
            'period' => $this->periodLabel($type, $data->periodStart, $data->periodEnd, $language),
            'activities' => $counts['activities'],
            'completed' => $counts['completed'],
            'ongoing' => $counts['ongoing'],
            'waiting' => $counts['waiting'],
            'cross' => $counts['cross_month'],
            'open' => $counts['ongoing'] + $counts['waiting'],
        ], $language->value);
    }

    /** Escapes Markdown control characters in text that a person wrote and flattens it to one line. */
    public function escape(string $text): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $text));

        return (string) preg_replace('/([\\\\`*_{}\[\]<>#|~!])/', '\\\\$1', $flat);
    }

    private function overview(ReportDataSet $data, ReportType $type, Language $language): string
    {
        $c = $data->counts();
        $l = fn (string $key): string => $this->label($key, $language);

        return implode("\n", [
            '- **'.$l('project').':** '.$this->escape($data->projectName),
            '- **'.$l('period').':** '.$this->periodLabel($type, $data->periodStart, $data->periodEnd, $language),
            '- **'.$l('activities').':** '.$c['activities'],
            '- **'.$l('completed_tasks').':** '.$c['completed'],
            '- **'.$l('ongoing_tasks').':** '.$c['ongoing'],
            '- **'.$l('waiting_tasks').':** '.$c['waiting'],
            '- **'.$l('cross_month_tasks').':** '.$c['cross_month'],
            '- **'.$l('incidents').':** '.$c['incidents'],
        ]);
    }

    private function completed(ReportDataSet $data, Language $language): string
    {
        if ($data->completedTaskIds === []) {
            return $this->none($language);
        }

        $rows = [];

        foreach ($data->completedTaskIds as $id) {
            $task = $data->tasks[$id];
            $rows[] = [$this->escape($task['title']), $this->date((string) $task['completed'], $language), (string) count($data->activitiesOf($id))];
        }

        return $this->table([$this->label('task', $language), $this->label('completed_on', $language), $this->label('activities', $language)], $rows);
    }

    private function detailed(ReportDataSet $data, Language $language): string
    {
        if ($data->activities === []) {
            return $this->none($language);
        }

        $blocks = [];

        foreach ($this->taskIdsByTitle($data, array_values(array_unique(array_map(fn (array $a): int => $a['task_id'], $data->activities)))) as $id) {
            $lines = ['### '.$this->escape($data->tasks[$id]['title'] ?? '#'.$id), ''];

            foreach ($data->activitiesOf($id) as $a) {
                $lines[] = '- '.$this->date($a['date'], $language).' — '.$this->typeLabel($a['type'], $language).': '.$this->escape($a['summary']);
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private function ongoing(ReportDataSet $data, Language $language): string
    {
        $ids = [...$data->ongoingTaskIds, ...$data->waitingTaskIds];

        if ($ids === []) {
            return $this->none($language);
        }

        $rows = [];

        foreach ($this->taskIdsByTitle($data, $ids) as $id) {
            $task = $data->tasks[$id];
            $rows[] = [$this->escape($task['title']), $this->statusLabel($task, $language), $task['last_activity'] === null ? '-' : $this->date($task['last_activity'], $language)];
        }

        return $this->table([$this->label('task', $language), $this->label('status', $language), $this->label('last_activity', $language)], $rows);
    }

    private function crossMonth(ReportDataSet $data, Language $language): string
    {
        if ($data->crossMonthTaskIds === []) {
            return $this->none($language);
        }

        $rows = [];

        foreach ($data->crossMonthTaskIds as $id) {
            $task = $data->tasks[$id];
            $rows[] = [
                $this->escape($task['title']),
                $task['started'] === null ? '-' : $this->date($task['started'], $language),
                $this->statusLabel($task, $language),
                (string) count($data->activitiesOf($id)),
            ];
        }

        return $this->table([$this->label('task', $language), $this->label('started', $language), $this->label('status', $language), $this->label('activities', $language)], $rows);
    }

    private function incidents(ReportDataSet $data, Language $language): string
    {
        if ($data->incidentActivityIds === []) {
            return $this->none($language);
        }

        $index = array_flip($data->incidentActivityIds);
        $lines = [];

        foreach ($data->activities as $a) {
            if (isset($index[$a['id']])) {
                $title = $this->escape($data->tasks[$a['task_id']]['title'] ?? '#'.$a['task_id']);
                $lines[] = '- '.$this->date($a['date'], $language).' — '.$this->typeLabel($a['type'], $language).' ('.$title.'): '.$this->escape($a['summary']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function taskIdsByTitle(ReportDataSet $data, array $ids): array
    {
        usort($ids, fn (int $x, int $y): int => [mb_strtolower($data->tasks[$x]['title'] ?? ''), $x] <=> [mb_strtolower($data->tasks[$y]['title'] ?? ''), $y]);

        return $ids;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function table(array $headers, array $rows): string
    {
        $lines = ['| '.implode(' | ', $headers).' |', '| '.implode(' | ', array_fill(0, count($headers), '---')).' |'];

        foreach ($rows as $row) {
            $lines[] = '| '.implode(' | ', $row).' |';
        }

        return implode("\n", $lines);
    }

    private function none(Language $language): string
    {
        return $this->label('none', $language);
    }

    private function label(string $key, Language $language): string
    {
        return (string) Lang::get('report.labels.'.$key, [], $language->value);
    }

    private function typeLabel(string $type, Language $language): string
    {
        return (string) Lang::get('report.activity_types.'.$type, [], $language->value);
    }

    /**
     * @param  array{status: string, waiting_reason: string|null}  $task
     */
    private function statusLabel(array $task, Language $language): string
    {
        $label = (string) Lang::get('report.statuses.'.$task['status'], [], $language->value);

        if ($task['status'] === 'waiting' && $task['waiting_reason'] !== null) {
            $label .= ' ('.$this->label('waiting_for', $language).' '.(string) Lang::get('report.waiting_reasons.'.$task['waiting_reason'], [], $language->value).')';
        }

        return $label;
    }

    private function date(string $date, Language $language): string
    {
        return $this->at($date, $language)->translatedFormat('j M Y');
    }

    private function at(string $date, Language $language): CarbonImmutable
    {
        /** @var CarbonImmutable $parsed */
        $parsed = CarbonImmutable::parse($date)->locale($language->value);

        return $parsed;
    }
}
