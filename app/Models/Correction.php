<?php

namespace App\Models;

use App\Enums\CorrectionType;
use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\CorrectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §21, §49 corrections: append-only log used for the User Correction Rate.
 */
#[Fillable(['user_id', 'inbound_message_id', 'correction_type', 'before', 'after'])]
class Correction extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<CorrectionFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'correction_type' => CorrectionType::class,
            'before' => 'array',
            'after' => 'array',
        ];
    }

    /**
     * @return BelongsTo<InboundMessage, $this>
     */
    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class);
    }
}
