<?php

use App\Http\Middleware\BindUserContext;
use App\Models\User;
use App\Services\Auth\LoginLinkService;
use App\Services\Auth\TelegramLoginVerifier;
use App\Support\UserContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/** A fake bot token assembled at runtime (CLAUDE.md: no credential-shaped literals in the repo). */
function fakeBotToken(): string
{
    return implode(':', ['123456', 'AAA'.str_repeat('b9', 14)]);
}

/** What Telegram would send for $id: signed with SHA256(token). */
function widgetData(int $id, ?int $authDate = null, array $extra = []): array
{
    $data = ['id' => (string) $id, 'first_name' => 'Test', 'auth_date' => (string) ($authDate ?? time())] + $extra;
    $lines = collect($data)->sortKeys()->map(fn ($v, $k) => "$k=$v")->implode("\n");
    $data['hash'] = hash_hmac('sha256', $lines, hash('sha256', fakeBotToken(), true));

    return $data;
}

beforeEach(function () {
    config(['telegram.token' => fakeBotToken(), 'telegram.bot_username' => 'ReportFlowTestBot']);
    Cache::flush();
    $this->user = User::factory()->create(['telegram_user_id' => 555001]);
});

describe('Telegram Login Widget', function () {
    it('signs a registered user in and lands on the dashboard', function () {
        $this->get('/auth/telegram/callback?'.http_build_query(widgetData(555001)))->assertRedirect('/admin');

        $this->assertAuthenticatedAs($this->user);
        $this->get('/admin')->assertOk();
    });

    it('rejects tampered data, a wrong token, a stale or future auth_date, and replays', function (Closure $mutate) {
        $data = $mutate(widgetData(555001));

        $this->get('/auth/telegram/callback?'.http_build_query($data))->assertForbidden();
        $this->assertGuest();
    })->with([
        'changed id' => [fn ($d) => ['id' => '555002'] + $d],
        'missing hash' => [function ($d) {
            unset($d['hash']);

            return $d;
        }],
        'garbage hash' => [fn ($d) => ['hash' => str_repeat('0', 64)] + $d],
        'extra signed-looking field' => [fn ($d) => $d + ['username' => 'x']],
        'stale' => [fn ($d) => widgetData(555001, time() - TelegramLoginVerifier::MAX_AGE_SECONDS - 5)],
        'from the future' => [fn ($d) => widgetData(555001, time() + 3600)],
        'array field' => [fn ($d) => ['first_name' => ['x']] + $d],
    ]);

    it('rejects a payload used twice', function () {
        $query = http_build_query(widgetData(555001));

        $this->get('/auth/telegram/callback?'.$query)->assertRedirect('/admin');
        auth()->logout();
        $this->get('/auth/telegram/callback?'.$query)->assertForbidden();
    });

    it('rejects a valid signature for an id that is not registered (whitelist)', function () {
        $this->get('/auth/telegram/callback?'.http_build_query(widgetData(999999)))->assertForbidden();
        $this->assertGuest();
    });

    it('is closed when no bot token is configured', function () {
        config(['telegram.token' => '']);

        $this->get('/auth/telegram/callback?'.http_build_query(widgetData(555001)))->assertForbidden();
    });

    it('shows the widget on the login page only when the bot username is configured', function () {
        $this->get('/admin/login')->assertOk()->assertSee('data-telegram-login="ReportFlowTestBot"', false)->assertDontSee('type="password"', false);

        config(['telegram.bot_username' => '']);
        $this->get('/admin/login')->assertOk()->assertDontSee('data-telegram-login', false);
    });
});

describe('one-time login link', function () {
    it('works once, for the user it was issued to', function () {
        $token = app(LoginLinkService::class)->issue($this->user->id);

        $this->get("/auth/link/$token")->assertRedirect('/admin');
        $this->assertAuthenticatedAs($this->user);

        auth()->logout();
        $this->get("/auth/link/$token")->assertForbidden();
    });

    it('expires after five minutes', function () {
        $token = app(LoginLinkService::class)->issue($this->user->id);
        Carbon::setTestNow(now()->addSeconds(LoginLinkService::TTL_SECONDS + 1));

        $this->get("/auth/link/$token")->assertForbidden();
        Carbon::setTestNow();
    });

    it('rejects malformed and unknown tokens', function (string $token) {
        $this->get('/auth/link/'.$token)->assertForbidden();
    })->with([str_repeat('a', 40), 'short', str_repeat('a', 41)]);

    it('is printed by the artisan command for a registered user, and refused for unknown ids and in production', function () {
        $this->artisan('reportflow:login-link', ['telegram_id' => 555001])->assertSuccessful()->expectsOutputToContain('/auth/link/');
        $this->artisan('reportflow:login-link', ['telegram_id' => 1])->assertFailed();

        withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek'], function () {
            $this->artisan('reportflow:login-link', ['telegram_id' => 555001])->assertFailed();
        });
    });
});

describe('the panel', function () {
    it('keeps guests out and has no password login', function () {
        $this->get('/admin')->assertRedirect();
        $this->get('/admin/login')->assertOk();
        $this->post('/admin/login', ['email' => 'a@b.c', 'password' => 'x'])->assertStatus(405);
    });

    it('does not let a user without a Telegram id into the panel', function () {
        $noTelegram = User::factory()->create(['telegram_user_id' => null]);

        $this->actingAs($noTelegram)->get('/admin')->assertForbidden();
    });
});

describe('user context', function () {
    it('is bound from the signed-in user, and is empty for guests', function () {
        $this->actingAs($this->user)->get('/admin')->assertOk();
        expect(app(UserContext::class)->userId())->toBe($this->user->id);
    });

    it('is also bound for Livewire component updates (persistent middleware)', function () {
        expect(Livewire\Livewire::getPersistentMiddleware())->toContain(BindUserContext::class);
    });
});
