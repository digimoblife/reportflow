<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('serves the dashboard over HTTPS only, has no password login, and does not seed a dev user when APP_ENV is production', function () {
    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'PDF_RENDERER' => 'gotenberg', 'OPS_PROBE' => 'real', 'DEV_USER_EMAIL' => 'dev@example.test', 'DEV_USER_PASSWORD' => 'not-a-real-password'], function () {
        expect(app()->environment())->toBe('production')
            ->and(config('app.dev_user.email'))->toBe('dev@example.test');

        // 1. The dashboard is HTTPS-only in production (PRD §56): plain HTTP gets a clean 404, never a login form.
        $this->get('http://localhost/admin/login')->assertNotFound();
        $this->get('http://localhost/admin')->assertNotFound();

        // 2. Over HTTPS the Telegram login page is served; there is no password form, and guests are redirected to it.
        $this->get('https://localhost/admin/login')->assertOk()->assertDontSee('type="password"', false);
        $this->get('https://localhost/admin')->assertRedirect();

        // 3. DatabaseSeeder does not create the dev user in production, even when DEV_USER_* is set.
        // --force is what a deploy passes; the guard must hold without the confirmation prompt.
        $userCountBefore = User::count();
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        expect(User::count())->toBe($userCountBefore);
    });
});

it('keeps debug off, cookies secure and links https in production, whatever the .env says (PRD §56)', function () {
    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'PDF_RENDERER' => 'gotenberg', 'OPS_PROBE' => 'real', 'APP_DEBUG' => 'true', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_HTTP_ONLY' => 'false', 'SESSION_SAME_SITE' => 'none'], function () {
        expect(config('app.debug'))->toBeFalse()
            ->and(config('session.secure'))->toBeTrue()
            ->and(config('session.http_only'))->toBeTrue()
            ->and(config('session.same_site'))->toBe('lax')
            ->and(url('/x'))->toStartWith('https://');
    });
});

it('answers only for its own host in production', function () {
    // APP_URL comes from .env (http://localhost), and dotenv re-reads it on every app boot, so the allowed host is "localhost".
    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'PDF_RENDERER' => 'gotenberg', 'OPS_PROBE' => 'real'], function () {
        $this->get('https://localhost/admin/login')->assertOk();
        $this->get('https://evil.example.test/admin/login')->assertStatus(400);
    });
});

it('does not force https or tighten cookies outside production', function () {
    expect(url('/x'))->toStartWith('http://');
});

it('redirects the bare domain to the dashboard instead of rendering a page without assets', function () {
    $this->get('/')->assertRedirect('/admin');
});
