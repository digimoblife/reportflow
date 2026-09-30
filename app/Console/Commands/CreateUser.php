<?php

namespace App\Console\Commands;

use App\Enums\Language;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The only way to let someone use the bot (PRD §56 whitelist = users.telegram_user_id).
 *
 * TODO(M5): the synthetic email and unusable password go away when the Telegram Login Widget
 * replaces email/password login (users.email and users.password are still NOT NULL).
 */
#[Signature('reportflow:user:create {telegram_id : Numeric Telegram user id} {--name= : Display name} {--email= : Existing user\'s email to link, or the email for the new user} {--language=id : Default language (id|en)} {--timezone=Asia/Jakarta : IANA timezone}')]
#[Description('Register a Telegram user (whitelist), or link a Telegram id to an existing user by --email')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $telegramId = (string) $this->argument('telegram_id');
        $language = Language::tryFrom((string) $this->option('language'));
        $timezone = (string) $this->option('timezone');

        if (preg_match('/^[1-9][0-9]{0,18}$/', $telegramId) !== 1) {
            $this->components->error('telegram_id must be a positive number.');

            return self::FAILURE;
        }

        if ($language === null) {
            $this->components->error('--language must be "id" or "en".');

            return self::FAILURE;
        }

        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            $this->components->error('--timezone must be a valid IANA timezone, e.g. Asia/Jakarta.');

            return self::FAILURE;
        }

        if (User::query()->where('telegram_user_id', (int) $telegramId)->exists()) {
            $this->components->error('A user with this Telegram id already exists.');

            return self::FAILURE;
        }

        $email = $this->option('email');
        $existing = is_string($email) && $email !== '' ? User::query()->where('email', $email)->first() : null;

        if ($existing !== null) {
            if ($existing->telegram_user_id !== null) {
                $this->components->error('That user is already linked to a Telegram id.');

                return self::FAILURE;
            }

            $existing->update(['telegram_user_id' => (int) $telegramId]);
            $this->components->info("Linked Telegram id to existing user #{$existing->id}.");

            return self::SUCCESS;
        }

        $user = User::query()->create([
            'name' => (string) ($this->option('name') ?: "Telegram {$telegramId}"),
            'email' => is_string($email) && $email !== '' ? $email : "tg{$telegramId}@telegram.invalid",
            // Random and never shown: this account signs in through Telegram only.
            'password' => Hash::make(Str::random(64)),
            'telegram_user_id' => (int) $telegramId,
            'default_language' => $language,
            'timezone' => $timezone,
        ]);

        $this->components->info("Created user #{$user->id} for Telegram id {$telegramId}.");

        return self::SUCCESS;
    }
}
