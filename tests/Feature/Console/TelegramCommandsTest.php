<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TelegramPayload;

beforeEach(function () {
    config(['app.url' => 'https://reportflow.example.test']);
});

describe('telegram:set-webhook', function () {
    it('prints the payload on --dry-run without secrets and without calling Telegram', function () {
        $path = (string) config('telegram.webhook_path');
        $secret = (string) config('telegram.secret_token');

        expect(Artisan::call('telegram:set-webhook', ['--dry-run' => true]))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain('"method": "setWebhook"')
            ->toContain('"message"')->toContain('"edited_message"')->not->toContain('callback_query')
            ->not->toContain($path)->not->toContain($secret)
            ->toContain('[hidden');

        expect(fakeTelegram()->webhooks)->toBe([]);
    });

    it('registers the webhook through the client', function () {
        $this->artisan('telegram:set-webhook')->assertSuccessful();

        $call = fakeTelegram()->webhooks[0];
        expect($call['url'])->toBe('https://reportflow.example.test/'.config('telegram.webhook_path'))
            ->and($call['secret_token'])->toBe(config('telegram.secret_token'))
            ->and($call['allowed_updates'])->toBe(['message', 'edited_message']);
    });

    it('validates the configuration before doing anything', function (array $config, string $error) {
        config($config);

        $this->artisan('telegram:set-webhook', ['--dry-run' => true])
            ->expectsOutputToContain($error)
            ->assertFailed();

        $this->artisan('telegram:set-webhook')->assertFailed();
        expect(fakeTelegram()->webhooks)->toBe([]);
    })->with([
        'short path' => [['telegram.webhook_path' => 'too-short'], 'TELEGRAM_WEBHOOK_PATH'],
        'path with slash' => [['telegram.webhook_path' => str_repeat('ab/', 12)], 'TELEGRAM_WEBHOOK_PATH'],
        'missing secret' => [['telegram.secret_token' => ''], 'TELEGRAM_BOT_SECRET_TOKEN'],
        'secret with bad characters' => [['telegram.secret_token' => 'bad secret!'], 'TELEGRAM_BOT_SECRET_TOKEN'],
        'secret too long' => [['telegram.secret_token' => str_repeat('a', 257)], 'TELEGRAM_BOT_SECRET_TOKEN'],
        'http app url' => [['app.url' => 'http://reportflow.example.test'], 'APP_URL'],
        'extra update types' => [['telegram.allowed_updates' => ['message', 'edited_message', 'callback_query']], 'allowed_updates'],
        'missing update type' => [['telegram.allowed_updates' => ['message']], 'allowed_updates'],
    ]);
});

describe('telegram:sync-commands', function () {
    it('prints both language sets on --dry-run', function () {
        expect(Artisan::call('telegram:sync-commands', ['--dry-run' => true]))->toBe(0);
        $output = Artisan::output();

        expect($output)->toContain('"description": "Mulai dan buat project pertama"')
            ->toContain('"description": "Begin and create your first project"')
            ->not->toContain('"command": "update"');

        expect(fakeTelegram()->commandSets)->toBe([]);
    });

    it('sends the default list and the English list through the client', function () {
        $this->artisan('telegram:sync-commands')->assertSuccessful();

        $sets = fakeTelegram()->commandSets;
        expect($sets)->toHaveCount(2)
            ->and($sets[0]['language_code'])->toBeNull()
            ->and($sets[1]['language_code'])->toBe('en')
            ->and(array_column($sets[0]['commands'], 'command'))->toBe(array_column($sets[1]['commands'], 'command'))
            ->and($sets[0]['commands'])->toHaveCount(14);
    });
});

describe('reportflow:user:create', function () {
    it('registers a Telegram user who can then use the bot', function () {
        Queue::fake();

        $this->artisan('reportflow:user:create', ['telegram_id' => '777001', '--name' => 'Budi', '--language' => 'en'])->assertSuccessful();

        $user = User::query()->where('telegram_user_id', 777001)->sole();
        expect($user->name)->toBe('Budi')
            ->and($user->email)->toBe('tg777001@telegram.invalid')
            ->and($user->default_language->value)->toBe('en')
            ->and($user->timezone)->toBe('Asia/Jakarta')
            ->and($user->password)->not->toBe('')
            ->and(Hash::info($user->password)['algoName'])->not->toBe('unknown');

        postTelegram(TelegramPayload::message('/help', from: 777001))->assertOk();
        expect(fakeTelegram()->sent)->toHaveCount(1);
    });

    it('does not print the password or anything secret', function () {
        $this->artisan('reportflow:user:create', ['telegram_id' => '777002'])
            ->expectsOutputToContain('Created user')
            ->assertSuccessful();

        $hash = User::query()->where('telegram_user_id', 777002)->value('password');
        $this->artisan('reportflow:user:create', ['telegram_id' => '777003'])->doesntExpectOutputToContain($hash)->assertSuccessful();
    });

    it('rejects a Telegram id that is already registered', function () {
        registerTelegramUser(777004);

        $this->artisan('reportflow:user:create', ['telegram_id' => '777004'])->assertFailed();

        expect(User::query()->where('telegram_user_id', 777004)->count())->toBe(1);
    });

    it('links the Telegram id to an existing user by email', function () {
        $existing = User::factory()->create(['email' => 'dev@example.test', 'telegram_user_id' => null]);

        $this->artisan('reportflow:user:create', ['telegram_id' => '777005', '--email' => 'dev@example.test'])->assertSuccessful();

        expect($existing->fresh()->telegram_user_id)->toBe(777005)
            ->and(User::query()->count())->toBe(1);
    });

    it('refuses to relink a user that already has a Telegram id', function () {
        User::factory()->create(['email' => 'linked@example.test', 'telegram_user_id' => 777006]);

        $this->artisan('reportflow:user:create', ['telegram_id' => '777007', '--email' => 'linked@example.test'])->assertFailed();
    });

    it('validates input', function (array $arguments) {
        $this->artisan('reportflow:user:create', $arguments)->assertFailed();

        expect(User::query()->count())->toBe(0);
    })->with([
        'not a number' => [['telegram_id' => 'abc']],
        'zero' => [['telegram_id' => '0']],
        'negative' => [['telegram_id' => '-5']],
        'bad language' => [['telegram_id' => '1', '--language' => 'fr']],
        'bad timezone' => [['telegram_id' => '1', '--timezone' => 'Mars/Olympus']],
    ]);
});
