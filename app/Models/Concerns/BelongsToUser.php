<?php

namespace App\Models\Concerns;

use App\Exceptions\CrossUserWriteException;
use App\Models\User;
use App\Support\UserContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For models with their own user_id column: scopes queries by user_id and fills it on create.
 */
trait BelongsToUser
{
    use HasUserScope;

    public static function bootBelongsToUser(): void
    {
        static::creating(function (Model $model): void {
            $context = app(UserContext::class);
            $contextUserId = $context->userId();

            if ($contextUserId === null) {
                return;
            }

            $recordUserId = $model->getAttribute('user_id');

            if ($recordUserId === null) {
                $model->setAttribute('user_id', $contextUserId);
            } elseif ((int) $recordUserId !== $contextUserId) {
                throw CrossUserWriteException::for($model::class, (int) $recordUserId, $contextUserId);
            }
        });
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    public function applyUserScope(Builder $query, int $userId): void
    {
        $query->where($this->qualifyColumn('user_id'), $userId);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
