<?php

use App\Models\Project;
use App\Models\User;
use App\Support\UserContext;
use Tests\Support\FakeSecrets;
use Tests\Support\TelegramPayload;

// `/project baru <name>`: creating a project from Telegram, through the same ProjectService as onboarding and the dashboard.

beforeEach(function () {
    $this->user = registerTelegramUser(555001, ['timezone' => 'Asia/Jakarta']);
    actingAsUser($this->user);
    $this->existing = Project::factory()->create(['name' => 'Harbor Portal', 'slug' => 'harbor-portal']);
});

function reply(string $text, int $messageId = 10): string
{
    send($text, $messageId);
    app(UserContext::class)->set(test()->user->id);

    return collect(fakeTelegram()->sent)->last()['text'];
}

function projectNames(): array
{
    return Project::query()->orderBy('id')->pluck('name')->all();
}

it('creates a project, in Indonesian and with the English keyword', function (string $command, string $name) {
    expect(isVariantOf(reply($command), 'onboarding.project_created', 'id', ['project' => $name]))->toBeTrue()
        ->and(projectNames())->toBe(['Harbor Portal', $name])
        ->and(Project::query()->where('name', $name)->sole()->status->value)->toBe('active');
})->with([
    'baru' => ['/project baru Kedai App', 'Kedai App'],
    'new' => ['/project new Kedai App', 'Kedai App'],
    'extra spaces' => ['/project   baru   Kedai    App ', 'Kedai App'],
]);

it('uses the project that already has that name instead of making a duplicate', function () {
    expect(isVariantOf(reply('/project baru harbor portal'), 'onboarding.project_exists', 'id', ['project' => 'Harbor Portal']))->toBeTrue()
        ->and(projectNames())->toBe(['Harbor Portal']);
});

it('explains the syntax when the name is missing and refuses a name without letters or digits', function () {
    expect(isVariantOf(reply('/project baru'), 'onboarding.new_project_usage'))->toBeTrue();
    expect(isVariantOf(reply('/project baru ???', 11), 'onboarding.name_invalid'))->toBeTrue()
        ->and(projectNames())->toBe(['Harbor Portal']);
});

it('never stores a secret as a project name', function () {
    $secret = FakeSecrets::githubToken();

    $text = reply("/project baru $secret");

    expect(isVariantOf($text, 'security.credential_detected', 'id', ['count' => 1]))->toBeTrue()
        ->and(projectNames())->toBe(['Harbor Portal'])
        ->and(implode(' ', fakeTelegram()->allTexts()))->not->toContain($secret);
});

it('still shows the list and the detail with the plain /project forms', function () {
    expect(reply('/project'))->toContain('Harbor Portal');
    expect(reply('/project harbor', 11))->toContain('Harbor Portal')->and(projectNames())->toBe(['Harbor Portal']);
});

it('keeps projects of different users apart', function () {
    $other = registerTelegramUser(555002, ['timezone' => 'Asia/Jakarta']);
    send('/project baru Harbor Portal', 20);

    expect(Project::query()->count())->toBe(1);   // the second user has their own scope: the call above is by user 555001

    postTelegram(TelegramPayload::message('/project baru Mine', 555002, 21))->assertOk();
    app(UserContext::class)->runAsSystem(fn () => expect(Project::query()->where('user_id', $other->id)->pluck('name')->all())->toBe(['Mine']));
});
