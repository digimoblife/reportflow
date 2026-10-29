<?php

namespace App\Services\Telegram;

use App\Enums\Language;
use App\Models\Person;
use App\Models\Project;
use App\Models\User;
use App\Services\Scrum\ScrumService;
use App\Services\Scrum\ScrumSessionManager;
use App\Services\Scrum\ScrumTextParser;
use Illuminate\Support\Str;

class ScrumTelegramHandler
{
    public function __construct(
        private readonly ScrumSessionManager $session,
        private readonly ScrumService $scrum,
        private readonly ScrumTextParser $parser,
        private readonly TelegramMessenger $messenger,
    ) {}

    public function register(CallbackRouter $router): void
    {
        $router->onScrum(fn (ScrumCallback $data, TelegramUpdate $update, User $user, Language $language) => $this->handleCallback($data, $update, $user, $language));
    }

    public function start(User $user, int $chatId, Language $language): void
    {
        $projects = Project::query()->where('status', 'active')->orderBy('name')->get();

        if ($projects->isEmpty()) {
            $this->messenger->trySend($chatId, 'Belum ada project aktif. Silakan buat project terlebih dahulu di menu Pengaturan Dashboard.');

            return;
        }

        if ($projects->count() === 1) {
            $project = $projects->first();
            $this->session->start($user->id, $project->id);
            $this->showMembers($user, $chatId, $language, $project);

            return;
        }

        $buttons = [];
        foreach ($projects as $project) {
            $buttons[] = [
                'text' => $project->name,
                'callback_data' => (new ScrumCallback('proj', (string) $project->id))->encode(),
            ];
        }

        $this->messenger->trySend(
            $chatId,
            "📋 *Sesi Daily Scrum*\nPilih project yang ingin dicatat:",
            keyboard: Keyboard::rows($buttons, 2)
        );
    }

    public function showMembers(User $user, int $chatId, Language $language, Project $project): void
    {
        $members = Person::query()->where('project_id', $project->id)->orderBy('name')->get();

        if ($members->isEmpty()) {
            $this->messenger->trySend(
                $chatId,
                "Belum ada anggota tim yang terdaftar di project *{$project->name}*.\n\nSilakan daftarkan anggota tim terlebih dahulu melalui menu *Manajemen Tim* di Web Dashboard."
            );

            return;
        }

        $currentSession = $this->session->get($user->id);
        $completed = $currentSession['completed_member_ids'] ?? [];

        $buttons = [];
        foreach ($members as $member) {
            $isDone = in_array($member->id, $completed, true);
            $icon = $isDone ? '✅ ' : '👤 ';
            $buttons[] = [
                'text' => $icon.$member->name,
                'callback_data' => (new ScrumCallback('mem', (string) $member->id))->encode(),
            ];
        }

        $keyboard = Keyboard::rows($buttons, 2);
        $keyboard[] = [
            ['text' => '🏁 Selesai', 'callback_data' => (new ScrumCallback('finish'))->encode()],
        ];

        $this->messenger->trySend(
            $chatId,
            "📋 *Sesi Scrum — {$project->name}*\nPilih anggota tim yang ingin dicatat:",
            keyboard: $keyboard
        );
    }

    /**
     * @param  array{
     *     project_id: int,
     *     person_id: int|null,
     *     person_name: string|null,
     *     awaiting_field: string|null,
     *     completed_member_ids: list<int>,
     *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
     * }  $sessionData
     */
    public function showMemberMenu(User $user, int $chatId, Language $language, Person $person, array $sessionData): void
    {
        /** @var Project|null $project */
        $project = Project::query()->whereKey($sessionData['project_id'])->first();
        $projectName = $project !== null ? $project->name : '-';

        $done = $sessionData['fields']['what_have_done'] ?? null;
        $issue = $sessionData['fields']['issue'] ?? null;
        $next = $sessionData['fields']['what_to_do_next'] ?? null;

        $donePreview = $done !== null ? '✅ '.Str::limit($done, 80) : '_(Belum diisi)_';
        $issuePreview = $issue !== null ? '⚠️ '.Str::limit($issue, 80) : '_(Tidak ada / kosong)_';
        $nextPreview = $next !== null ? '🎯 '.Str::limit($next, 80) : '_(Belum diisi)_';

        $text = "Mencatat Scrum untuk: *{$person->name}* ({$projectName})\n\n"
            ."• *What Have Done:*\n{$donePreview}\n\n"
            ."• *Issue (opsional):*\n{$issuePreview}\n\n"
            ."• *What To Do Next:*\n{$nextPreview}";

        $keyboard = [
            [
                ['text' => ($done !== null ? '✅' : '📝').' What Have Done', 'callback_data' => (new ScrumCallback('fld', 'done'))->encode()],
            ],
            [
                ['text' => ($issue !== null ? '✅' : '⚠️').' Issue (opsional)', 'callback_data' => (new ScrumCallback('fld', 'issue'))->encode()],
            ],
            [
                ['text' => ($next !== null ? '✅' : '🎯').' What To Do Next', 'callback_data' => (new ScrumCallback('fld', 'next'))->encode()],
            ],
            [
                ['text' => '💾 Simpan', 'callback_data' => (new ScrumCallback('save'))->encode()],
                ['text' => '⬅️ Ganti Orang', 'callback_data' => (new ScrumCallback('back'))->encode()],
            ],
        ];

        $this->messenger->trySend($chatId, $text, keyboard: $keyboard);
    }

    public function handleCallback(ScrumCallback $callback, TelegramUpdate $update, User $user, Language $language): void
    {
        $chatId = $update->chatId;

        if ($callback->action === 'proj') {
            $projectId = (int) $callback->target;
            $project = Project::query()->find($projectId);
            if ($project !== null) {
                $this->session->start($user->id, $project->id);
                $this->showMembers($user, $chatId, $language, $project);
            }

            return;
        }

        if ($callback->action === 'mem') {
            $personId = (int) $callback->target;
            $person = Person::query()->find($personId);
            if ($person !== null) {
                if ($this->session->get($user->id) === null && $person->project_id !== null) {
                    $this->session->start($user->id, $person->project_id);
                }
                $session = $this->session->selectMember($user->id, $personId, $person->name);
                if ($session !== null) {
                    $this->showMemberMenu($user, $chatId, $language, $person, $session);
                }
            }

            return;
        }

        if ($callback->action === 'fld') {
            $fieldKey = match ($callback->target) {
                'done' => 'what_have_done',
                'issue' => 'issue',
                'next' => 'what_to_do_next',
                default => null,
            };

            if ($fieldKey === null) {
                return;
            }

            $session = $this->session->get($user->id);
            $personName = $session['person_name'] ?? 'anggota tim';
            $this->session->setAwaitingField($user->id, $fieldKey);

            $prompt = match ($fieldKey) {
                'what_have_done' => "Ketik apa saja yang sudah dikerjakan/selesai oleh *{$personName}*:",
                'issue' => "Ketik kendala/issue yang dihadapi *{$personName}* (atau ketik '-' jika tidak ada):",
                default => "Ketik rencana pekerjaan *{$personName}* selanjutnya:",
            };

            $this->messenger->trySend($chatId, $prompt);

            return;
        }

        if ($callback->action === 'save') {
            $session = $this->session->get($user->id);
            if ($session === null || $session['person_id'] === null) {
                return;
            }

            $fields = $session['fields'];
            if (empty(trim((string) ($fields['what_have_done'] ?? ''))) && empty(trim((string) ($fields['what_to_do_next'] ?? '')))) {
                $this->messenger->tryAnswer((string) $update->callbackId, 'Silakan isi What Have Done atau What To Do Next sebelum menyimpan.');

                return;
            }

            $personId = (int) $session['person_id'];
            $projectId = (int) $session['project_id'];
            /** @var Person|null $person */
            $person = Person::query()->whereKey($personId)->first();
            /** @var Project|null $project */
            $project = Project::query()->whereKey($projectId)->first();

            $this->scrum->saveEntry($user, $projectId, $personId, $fields);
            $updatedSession = $this->session->markMemberCompleted($user->id, $personId);

            $allMembers = Person::query()->where('project_id', $projectId)->pluck('id')->all();
            $completed = $updatedSession['completed_member_ids'] ?? [];
            $remaining = array_diff($allMembers, $completed);

            if (! empty($remaining) && $project !== null) {
                $buttons = [];
                $remainingPersons = Person::query()->whereIn('id', $remaining)->orderBy('name')->get();
                foreach ($remainingPersons as $rem) {
                    $buttons[] = [
                        'text' => '👤 '.$rem->name,
                        'callback_data' => (new ScrumCallback('mem', (string) $rem->id))->encode(),
                    ];
                }

                $keyboard = Keyboard::rows($buttons, 2);
                $keyboard[] = [
                    ['text' => '🏁 Selesai', 'callback_data' => (new ScrumCallback('finish'))->encode()],
                ];

                $personName = $person !== null ? $person->name : 'Anggota';
                $this->messenger->trySend(
                    $chatId,
                    "✅ Catatan scrum *{$personName}* berhasil disimpan!\n\nLanjut catat anggota berikutnya?",
                    keyboard: $keyboard
                );
            } else {
                $projectName = $project !== null ? $project->name : '';
                $this->session->clear($user->id);
                $this->messenger->trySend(
                    $chatId,
                    "🎉 Semua catatan scrum untuk tim *{$projectName}* telah lengkap disimpan!"
                );
            }

            return;
        }

        if ($callback->action === 'back') {
            $session = $this->session->get($user->id);
            if ($session !== null) {
                /** @var Project|null $project */
                $project = Project::query()->whereKey($session['project_id'])->first();
                if ($project !== null) {
                    $this->showMembers($user, $chatId, $language, $project);
                }
            }

            return;
        }

        if ($callback->action === 'finish') {
            $this->session->clear($user->id);
            $this->messenger->trySend($chatId, 'Sesi Scrum telah selesai. Data telah tersimpan dengan aman.');
        }
    }

    public function handleTextInput(User $user, int $chatId, Language $language, string $text): void
    {
        $session = $this->session->get($user->id);
        if ($session === null || $session['awaiting_field'] === null) {
            return;
        }

        $field = $session['awaiting_field'];
        $cleanText = trim($text);
        if ($cleanText === '-') {
            $cleanText = '';
        }

        $updatedSession = $this->session->setFieldValue($user->id, $field, $cleanText);

        if ($updatedSession !== null && $updatedSession['person_id'] !== null) {
            /** @var Person|null $person */
            $person = Person::query()->whereKey($updatedSession['person_id'])->first();
            if ($person !== null) {
                /** @var array{
                 *     project_id: int,
                 *     person_id: int|null,
                 *     person_name: string|null,
                 *     awaiting_field: string|null,
                 *     completed_member_ids: list<int>,
                 *     fields: array{what_have_done: string|null, issue: string|null, what_to_do_next: string|null}
                 * } $typedSession */
                $typedSession = $updatedSession;
                $this->showMemberMenu($user, $chatId, $language, $person, $typedSession);
            }
        }
    }

    public function handleDirectPaste(User $user, int $chatId, Language $language, string $text): bool
    {
        if (! $this->parser->isScrumFormat($text)) {
            return false;
        }

        $parsedMembers = $this->parser->parse($text);
        if ($parsedMembers === []) {
            return false;
        }

        $savedNames = [];

        foreach ($parsedMembers as $parsed) {
            $name = $parsed['name'];
            if ($name === '') {
                continue;
            }

            /** @var Person|null $person */
            $person = Person::query()->where('name', 'ILIKE', $name)->first();

            // Try aliases
            if ($person === null) {
                $allPeople = Person::query()->get();
                foreach ($allPeople as $candidate) {
                    $known = array_map('mb_strtolower', [$candidate->name, ...array_map('strval', (array) $candidate->aliases)]);
                    if (in_array(mb_strtolower($name), $known, true)) {
                        $person = $candidate;
                        break;
                    }
                }
            }

            // If still not found, create person in first active project
            if ($person === null) {
                $firstProject = Project::query()->where('status', 'active')->first();
                if ($firstProject !== null) {
                    $person = Person::query()->create([
                        'name' => $name,
                        'project_id' => $firstProject->id,
                        'user_id' => $user->id,
                    ]);
                }
            }

            if ($person === null || $person->project_id === null) {
                continue;
            }

            $this->scrum->saveEntry($user, $person->project_id, $person->id, [
                'what_have_done' => $parsed['what_have_done'],
                'issue' => $parsed['issue'],
                'what_to_do_next' => $parsed['what_to_do_next'],
            ]);

            $savedNames[] = $person->name;
        }

        if ($savedNames === []) {
            return false;
        }

        $namesList = implode(', ', $savedNames);
        $this->messenger->trySend(
            $chatId,
            "✅ Berhasil mencatat Daily Scrum untuk: *{$namesList}*!\nData telah tersimpan ke sistem."
        );

        return true;
    }
}
