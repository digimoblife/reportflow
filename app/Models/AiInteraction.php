<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\AiInteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PRD §49, §54, §79 ai_interactions. `input` must only ever contain post-redaction text.
 */
#[Fillable([
    'user_id', 'project_id', 'inbound_message_id', 'report_id', 'purpose', 'model', 'prompt_version', 'input',
    'output', 'tokens_input', 'tokens_output', 'latency_ms', 'success', 'error',
])]
class AiInteraction extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<AiInteractionFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'tokens_input' => 'integer',
            'tokens_output' => 'integer',
            'latency_ms' => 'integer',
            'success' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<InboundMessage, $this>
     */
    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(InboundMessage::class);
    }

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }
}
