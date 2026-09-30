<?php

namespace App\Services\Telegram;

use App\Enums\InboundMessageStatus;
use App\Enums\Language;
use App\Enums\MessageSource;
use App\Jobs\ProcessInboundMessage;
use App\Models\InboundMessage;
use App\Models\User;
use App\Services\Redaction\RedactionResult;
use App\Services\Redaction\RedactionService;
use App\Services\Worklog\ProjectService;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turns a Telegram update into a stored, queued inbound message (PRD §7, §23, §48, §56).
 *
 * Order of operations for a worklog text: identify user -> redact -> INSERT ... ON CONFLICT DO NOTHING
 * -> acknowledge -> dispatch. The raw text never leaves this class: only the redacted text is stored,
 * logged or queued, and the un-redacted string is not passed to anything except RedactionService.
 */
class TelegramIngestionService
{
    private const DONE_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly UserContext $context,
        private readonly TelegramMessenger $messenger,
        private readonly BotMessages $messages,
        private readonly LanguageDetector $languages,
        private readonly CommandRouter $commands,
        private readonly OnboardingState $onboarding,
        private readonly ProjectService $projects,
    ) {}

    public function handle(TelegramUpdate $update): void
    {
        if (! $update->isPrivateUserChat()) {
            return;
        }

        $user = User::query()->where('telegram_user_id', $update->fromId)->first();

        if ($user === null) {
            // Silent by design: no reply (would prove the bot is alive), nothing stored.
            $this->logUnregisteredSender($update->fromId);

            return;
        }

        $this->context->runAs($user->id, fn () => $this->handleForUser($update, $user));
    }

    private function handleForUser(TelegramUpdate $update, User $user): void
    {
        $default = $user->default_language;
        $content = $update->content();

        if (! $update->isEdit() && $update->command() !== null) {
            $this->once($update, fn () => $this->commands->handle($update->command()['name'], $user, $update->chatId, $default));

            return;
        }

        if ($content === null) {
            $this->once($update, fn () => $this->messenger->trySend(
                $update->chatId,
                $this->messages->get('unsupported.'.$update->unsupportedKind(), $default),
            ));

            return;
        }

        $redaction = RedactionService::forUser($user->id)->redact($content);

        if ($redaction->failed()) {
            $this->once($update, fn () => $this->messenger->trySend($update->chatId, $this->messages->get('security.redaction_error', $default)));

            return;
        }

        if (! $update->isEdit() && $this->onboarding->isAwaitingProjectName($user->id) && ! $update->hasAttachment()) {
            $this->once($update, fn () => $this->createFirstProject($redaction, $update, $user, $default));

            return;
        }

        $language = $this->languages->detect($redaction->text, $default);

        if ($update->isEdit()) {
            $this->handleEdit($update, $user, $redaction, $language);

            return;
        }

        $this->storeNew($update, $user, $redaction, $language);
    }

    private function createFirstProject(RedactionResult $redaction, TelegramUpdate $update, User $user, Language $language): void
    {
        if ($redaction->hasFindings()) {
            $this->notifyCredential($update->chatId, $redaction, $language);

            return; // still waiting for a usable name
        }

        $name = $this->projects->normalizeName($redaction->text);

        if ($name === null) {
            $this->messenger->trySend($update->chatId, $this->messages->get('onboarding.name_invalid', $language));

            return;
        }

        [$project, $created] = $this->projects->findOrCreate($name);
        $this->onboarding->clear($user->id);

        $this->messenger->trySend($update->chatId, $this->messages->get(
            $created ? 'onboarding.project_created' : 'onboarding.project_exists',
            $language,
            ['project' => $project->name],
        ));
    }

    private function storeNew(TelegramUpdate $update, User $user, RedactionResult $redaction, Language $language, bool $edited = false): void
    {
        $now = Carbon::now('UTC');
        $received = $update->date !== null ? Carbon::createFromTimestampUTC($update->date) : $now;

        $attachments = array_map(static fn (string $type): array => ['type' => $type], $update->attachmentTypes);

        // ON CONFLICT DO NOTHING: a duplicate delivery is a normal outcome, not an exception.
        $inserted = InboundMessage::query()->insertOrIgnore([
            'user_id' => $user->id,
            'source' => MessageSource::Telegram->value,
            'idempotency_key' => $update->idempotencyKey(),
            'telegram_chat_id' => $update->chatId,
            'telegram_message_id' => $update->messageId,
            'text' => $redaction->text,
            'attachments' => json_encode($attachments, JSON_THROW_ON_ERROR),
            'received_at' => $received,
            'edited_at' => $edited ? $now : null,
            'status' => InboundMessageStatus::Received->value,
            'reprocess_count' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $message = InboundMessage::query()->where('idempotency_key', $update->idempotencyKey())->firstOrFail();

        if ($inserted === 0) {
            // Duplicate delivery. If the first attempt died before dispatching, the message is still
            // `received`: dispatch again (the job claims the row atomically, so this cannot double-process).
            if ($message->status === InboundMessageStatus::Received) {
                ProcessInboundMessage::dispatch($message->id, $user->id);
            }

            return;
        }

        $replyId = $this->messenger->trySend($update->chatId, $this->messages->get('worklog.ack', $language), $update->messageId);

        if ($replyId !== null) {
            InboundMessage::query()->whereKey($message->id)->update(['reply_message_id' => $replyId]);
        }

        $this->notifyCredential($update->chatId, $redaction, $language);

        if ($update->hasAttachment()) {
            $this->messenger->trySend($update->chatId, $this->messages->get('worklog.attachment_ignored', $language));
        }

        ProcessInboundMessage::dispatch($message->id, $user->id);
    }

    private function handleEdit(TelegramUpdate $update, User $user, RedactionResult $redaction, Language $language): void
    {
        $existing = InboundMessage::query()->where('idempotency_key', $update->idempotencyKey())->first();

        if ($existing === null) {
            // We never stored the original (sent before registration, or lost): treat the edit as a new message.
            $this->storeNew($update, $user, $redaction, $language, edited: true);

            return;
        }

        $this->once($update, function () use ($existing, $update, $redaction, $language): void {
            InboundMessage::query()->where('idempotency_key', $update->idempotencyKey())->update([
                'text' => $redaction->text,
                'edited_at' => Carbon::now('UTC'),
            ]);

            $this->notifyCredential($update->chatId, $redaction, $language);

            // received/processing: the pending run picks up the new text, stay quiet.
            // Already finished: the notes made from the old text do not change (reprocess: M4).
            if (in_array($existing->status, [InboundMessageStatus::Processed, InboundMessageStatus::Failed, InboundMessageStatus::NeedsClarification], true)) {
                $this->messenger->trySend($update->chatId, $this->messages->get('worklog.edit_saved_notice', $language));
            }
        });
    }

    private function notifyCredential(int $chatId, RedactionResult $redaction, Language $language): void
    {
        if ($redaction->secretCount() > 0) {
            $this->messenger->trySend($chatId, $this->messages->get('security.credential_detected', $language, ['count' => $redaction->secretCount()]));
        }
    }

    /**
     * Runs a side-effect-only action once per delivered version of a message. The marker is written
     * AFTER success, so a failure leaves the update retryable.
     */
    private function once(TelegramUpdate $update, callable $action): void
    {
        $key = 'tg:done:'.$update->versionKey();

        if (Cache::has($key)) {
            return;
        }

        $action();

        Cache::put($key, true, self::DONE_TTL_SECONDS);
    }

    private function logUnregisteredSender(int $telegramUserId): void
    {
        if (Cache::add("tg:unregistered:{$telegramUserId}", true, (int) config('telegram.unregistered_log_ttl', 3600))) {
            Log::info('telegram.unregistered_sender', ['telegram_user_id' => $telegramUserId]);
        }
    }
}
