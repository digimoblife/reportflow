<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;

it('does not serve login page or admin panel, and does not seed dev user when APP_ENV is production', function () {
    $originalEnv = env('APP_ENV', 'testing');

    try {
        putenv('APP_ENV=production');
        $_ENV['APP_ENV'] = 'production';
        $_SERVER['APP_ENV'] = 'production';
        $this->refreshApplication();

        // Ensure test database remains reportflow_test under refreshed app
        config(['database.connections.pgsql.database' => 'reportflow_test']);
        $this->ensureRunningOnTestDatabase();

        expect(app()->environment())->toBe('production');

        // 1. /admin/login is not registered (returns clean 404, panel has no login)
        expect(Filament::getPanel('admin')->hasLogin())->toBeFalse();

        $loginResponse = $this->get('/admin/login');
        $loginResponse->assertNotFound();

        // 2. /admin returns clean 404 instead of 500 Route [login] not defined
        $adminResponse = $this->get('/admin');
        $adminResponse->assertNotFound();

        // 3. DatabaseSeeder does not create dev user in production
        $userCountBefore = User::count();
        $this->seed(DatabaseSeeder::class);
        expect(User::count())->toBe($userCountBefore);
    } finally {
        putenv("APP_ENV={$originalEnv}");
        $_ENV['APP_ENV'] = $originalEnv;
        $_SERVER['APP_ENV'] = $originalEnv;
        $this->refreshApplication();
    }
});
