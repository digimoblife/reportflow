<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

// Model events stay enabled: user-scoped models fill and guard user_id in `creating`.
class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database. Local environment only.
     *
     * TODO: hapus di M5 saat Telegram Login Widget diimplementasikan
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command->warn('Dev seeder is only allowed in local environment. Skipping.');

            return;
        }

        $this->seedDevUser();

        $this->call(DemoSeeder::class);
    }

    private function seedDevUser(): void
    {
        $email = config('app.dev_user.email');
        $password = config('app.dev_user.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            $this->command->info('DEV_USER_EMAIL or DEV_USER_PASSWORD not set. Skipping dev user creation.');

            return;
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Dev Admin',
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info("Dev user [{$email}] created/updated successfully.");
    }
}
