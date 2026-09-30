<?php

namespace Database\Factories;

use App\Enums\Language;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'telegram_user_id' => fake()->unique()->numberBetween(100_000_000, 9_999_999_999),
            'timezone' => 'Asia/Jakarta',
            'default_language' => Language::Indonesian,
            'workdays' => ['mon', 'tue', 'wed', 'thu', 'fri'],
            'reminders_enabled' => true,
        ];
    }

    /**
     * A user that has not linked a Telegram account (e.g. the local dev login before M5).
     */
    public function withoutTelegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'telegram_user_id' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
