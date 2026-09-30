<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Language;
use App\Models\Concerns\StoresTimestampsWithOffset;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property Language $default_language
 *
 * The account owner (PRD §49 users). Not user-scoped itself; it is the scope.
 * TODO(M5): email/password login is local-only until the Telegram Login Widget lands.
 */
#[Fillable(['name', 'email', 'password', 'telegram_user_id', 'timezone', 'default_language', 'workdays', 'reminders_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    use StoresTimestampsWithOffset;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'telegram_user_id' => 'integer',
            'default_language' => Language::class,
            'workdays' => 'array',
            'reminders_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Person, $this>
     */
    public function people(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /**
     * @return HasMany<InboundMessage, $this>
     */
    public function inboundMessages(): HasMany
    {
        return $this->hasMany(InboundMessage::class);
    }

    /**
     * @return HasMany<Correction, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class);
    }

    /**
     * @return HasMany<ReportTemplate, $this>
     */
    public function reportTemplates(): HasMany
    {
        return $this->hasMany(ReportTemplate::class);
    }

    /**
     * @return HasMany<ReminderRule, $this>
     */
    public function reminderRules(): HasMany
    {
        return $this->hasMany(ReminderRule::class);
    }

    /**
     * @return HasMany<AiInteraction, $this>
     */
    public function aiInteractions(): HasMany
    {
        return $this->hasMany(AiInteraction::class);
    }
}
