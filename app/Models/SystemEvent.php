<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use App\Models\Concerns\StoresTimestampsWithOffset;
use App\Models\Contracts\UserScoped;
use Database\Factories\SystemEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * PRD §78 operational events (failed deliveries, failed reports, purges, verified restores). Append-only; `context` holds
 * codes and counters only, never user text. user_id is empty for events that belong to the system itself.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string $type
 * @property array<string, mixed> $context
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'type', 'context'])]
class SystemEvent extends Model implements UserScoped
{
    use BelongsToUser;

    /** @use HasFactory<SystemEventFactory> */
    use HasFactory;

    use StoresTimestampsWithOffset;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['context' => 'array'];
    }
}
