<?php

namespace App\Filament\Pages;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\OutcomeState;
use App\Jobs\SyncTelegramBubbles;
use App\Models\InboundMessage;
use App\Models\Project;
use App\Models\User;
use App\Services\Telegram\CallbackData;
use App\Services\Telegram\ConfirmationComposer;
use App\Services\Worklog\Outcome;
use App\Services\Worklog\PendingAnswerService;
use App\Services\Worklog\ReprocessService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Computed;

/**
 * Inbox (PRD §22 page 3): notes that failed or wait for a clarification answer, from both channels. Answers go through
 * PendingAnswerService, the same code as the Telegram buttons, so whichever channel answers first wins and the other
 * one only finds the item already answered (PRD §23). A Telegram question is closed on the Telegram side by
 * SyncTelegramBubbles.
 */
class Inbox extends Page
{
    protected string $view = 'filament.pages.inbox';

    protected static ?int $navigationSort = 0;

    public ?string $notice = null;

    public string $noticeKind = 'info';

    public static function getNavigationLabel(): string
    {
        return (string) __('ui.dashboard.inbox.nav');
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return Heroicon::OutlinedInbox;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = InboundMessage::query()->whereIn('status', [InboundMessageStatus::Failed, InboundMessageStatus::NeedsClarification])->count();

        return $count > 0 ? (string) $count : null;
    }

    public function getTitle(): string|Htmlable
    {
        return (string) __('ui.dashboard.inbox.title');
    }

    public function answer(int $messageId, int $index, string $action, ?string $arg = null): void
    {
        $message = InboundMessage::query()->findOrFail($messageId);
        $result = app(PendingAnswerService::class)->answer($message, $index, $action, $arg === '' ? null : $arg, $this->timezone());

        if ($result === null) {
            $this->flash('already', false);

            return;
        }

        SyncTelegramBubbles::dispatchFor($message, SyncTelegramBubbles::ANSWERED, $index);
        $this->flash('answered', true);
    }

    public function reprocess(int $messageId): void
    {
        $message = InboundMessage::query()->findOrFail($messageId);
        $result = app(ReprocessService::class)->reprocess($message);

        if ($result === null) {
            $this->flash('busy', false);

            return;
        }

        SyncTelegramBubbles::dispatchFor($message, SyncTelegramBubbles::REPROCESSED, null, $result['question_message_ids']);
        $this->flash('reprocessing', true);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function entries(): array
    {
        $user = auth()->user();
        $language = $user instanceof User ? $user->default_language : Language::Indonesian;
        $composer = app(ConfirmationComposer::class);
        $projects = array_values(Project::query()->where('status', 'active')->orderBy('name')->get()->all());
        $entries = [];

        $messages = InboundMessage::query()
            ->whereIn('status', [InboundMessageStatus::Failed, InboundMessageStatus::NeedsClarification])
            ->orderByDesc('id')->limit(30)->get();

        foreach ($messages as $message) {
            $questions = [];

            foreach (Outcome::fromArray($message->outcome)->items ?? [] as $item) {
                if ($item->state !== OutcomeState::Pending) {
                    continue;
                }

                $view = $composer->question($message, $item, $language, $projects);
                $buttons = [];

                foreach ($view['keyboard'] as $row) {
                    foreach ($row as $button) {
                        $data = CallbackData::parse($button['callback_data']);

                        if ($data !== null) {
                            $buttons[] = ['label' => $button['text'], 'action' => $data->action, 'arg' => $data->arg];
                        }
                    }
                }

                $questions[] = ['index' => $item->index, 'text' => $view['text'], 'buttons' => $buttons];
            }

            $entries[] = [
                'id' => $message->id,
                'source' => $message->source->value,
                'status' => $message->status->value,
                'text' => $message->text,
                'at' => $message->received_at->copy()->setTimezone($this->timezone())->format('d M Y H:i'),
                'questions' => $questions,
            ];
        }

        return $entries;
    }

    private function timezone(): string
    {
        $user = auth()->user();

        return $user instanceof User ? $user->timezone : 'UTC';
    }

    private function flash(string $key, bool $success): void
    {
        $this->notice = (string) __('ui.dashboard.inbox.notices.'.$key);
        $this->noticeKind = $success ? 'success' : 'warning';
    }
}
