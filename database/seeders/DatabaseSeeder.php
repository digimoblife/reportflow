<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * TODO: hapus di M5 saat Telegram Login Widget diimplementasikan
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('Dev seeder is only allowed in local environment. Skipping.');

            return;
        }

        $email = env('DEV_USER_EMAIL');
        $password = env('DEV_USER_PASSWORD');

        if (empty($email) || empty($password)) {
            $this->command?->info('DEV_USER_EMAIL or DEV_USER_PASSWORD not set. Skipping dev user creation.');

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

        $this->command?->info("Dev user [{$email}] created/updated successfully.");
    }
}
