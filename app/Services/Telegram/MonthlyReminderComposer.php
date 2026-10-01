<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Enums\ReportType;
use App\Models\ReminderInstance;
use App\Models\User;
use App\Services\Reminder\MonthlySummary;
use App\Services\Report\ReportFactsBuilder;
use Illuminate\Support\Facades\Lang;

/**
 * The month-end report reminder with context (PRD §62): what the month holds per project, and what to do next.
 */
class MonthlyReminderComposer
{
    public function __construct(
        private readonly BotMessages $messages,
        private readonly MonthlySummary $summary,
        private readonly ReportFactsBuilder $facts,
    ) {}

    /**
     * @return array{text: string, keyboard: list<list<array<string, string>>>}
     */
    public function compose(ReminderInstance $instance, User $user, Language $language): array
    {
        $date = (string) $instance->reminder_date?->format('Y-m-d');
        [$from, $to] = $this->summary->range($date);
        $label = fn (string $key): string => (string) Lang::get('ui.report_review.'.$key, [], $language->value);

        $lines = [$this->messages->get('reminder.monthly_intro', $language, ['month' => $this->facts->periodLabel(ReportType::Monthly, $from, $to, $language)]), ''];

        foreach ($this->summary->build($user, $from, $to) as $row) {
            $c = $row['counts'];
            $lines[] = '📁 '.$row['project']->name;
            $lines[] = '   '.$label('activities').': '.$c['activities'].' · '.$label('completed').': '.$c['completed'].' · '.$label('ongoing').': '.$c['ongoing']
                .' · '.$label('waiting').': '.$c['waiting'].' · '.$label('cross_month').': '.$c['cross_month'];
        }

        $lines[] = '';
        $lines[] = $this->messages->get('reminder.monthly_ask', $language);

        $button = fn (string $label, string $action): array => [
            'text' => (string) Lang::get('ui.buttons.'.$label, [], $language->value),
            'callback_data' => (new ReminderCallback($instance->id, $action))->encode(),
        ];

        return ['text' => implode("\n", $lines), 'keyboard' => [
            [$button('reminder_generate', 'gen')],
            [$button('reminder_review', 'rev'), $button('reminder_later_day', 'later')],
        ]];
    }
}
