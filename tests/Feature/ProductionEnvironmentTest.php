<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;

it('does not serve login page or admin panel, and does not seed dev user when APP_ENV is production', function () {
    withAppEnvironment('production', ['TELEGRAM_CLIENT' => 'http', 'AI_PROVIDER' => 'deepseek', 'DEV_USER_EMAIL' => 'dev@example.test', 'DEV_USER_PASSWORD' => 'not-a-real-password'], function () {
        expect(app()->environment())->toBe('production')
            ->and(config('app.dev_user.email'))->toBe('dev@example.test');

        // 1. /admin/login is not registered (returns clean 404, panel has no login)
        expect(Filament::getPanel('admin')->hasLogin())->toBeFalse();

        $this->get('/admin/login')->assertNotFound();

        // 2. /admin returns clean 404 instead of 500 Route [login] not defined
        $this->get('/admin')->assertNotFound();

        // 3. DatabaseSeeder does not create the dev user in production, even when DEV_USER_* is set.
        // --force is what a deploy passes; the guard must hold without the confirmation prompt.
        $userCountBefore = User::count();
        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])->assertSuccessful();
        expect(User::count())->toBe($userCountBefore);
    });
});
