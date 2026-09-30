<?php

namespace App\Models;

use App\Enums\InboundMessageStatus;
use App\Enums\MessageSource;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\InboundMessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property MessageSource $source
 * @property string $idempotency_key
 * @property int|null $telegram_chat_id
 * @property int|null $telegram_message_id
 * @property int|null $reply_message_id
 * @property string $text
 * @property InboundMessageStatus $status
 * @property string|null $error
 * @property array<string, mixed>|null $outcome
 *
 * PRD §23, §48, §49 inbound_messages. `text` is always the post-redaction text.
 * reply_message_id is the bot's reply (the "⏳" message) that gets edited into the confirmation.
 */
#[Fillable([
    'user_id', 'source', 'idempotency_key', 'telegram_chat_id', 'telegram_message_id', 'reply_message_id',
    'text', 'attachments', 'received_at', 'edited_at', 'status', 'error', 'reprocess_count', 'outcome',
])]
class InboundMessage extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<InboundMessageFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    protected function casts(): array
    {
        return [
            'source' => MessageSource::class,
            'status' => InboundMessageStatus::class,
            'telegram_chat_id' => 'integer',
            'telegram_message_id' => 'integer',
            'reply_message_id' => 'integer',
            'attachments' => 'array',
            'outcome' => 'array',
            'received_at' => 'datetime',
            'edited_at' => 'datetime',
            'reprocess_count' => 'integer',
        ];
    }

    /**
     * @return HasMany<Activity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class);
    }

    /**
     * @return HasMany<TaskEvent, $this>
     */
    public function taskEvents(): HasMany
    {
        return $this->hasMany(TaskEvent::class);
    }

    /**
     * @return HasMany<Correction, $this>
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(Correction::class);
    }

    /**
     * @return HasMany<AiInteraction, $this>
     */
    public function aiInteractions(): HasMany
    {
        return $this->hasMany(AiInteraction::class);
    }
}
