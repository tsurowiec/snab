<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Every app table carries a user_id. Models using this trait are:
 *  - automatically scoped to the authenticated user (global scope "user"),
 *  - automatically assigned to the authenticated user on create.
 *
 * Outside of an authenticated request (queue, console) the scope is a no-op,
 * so always go through the owning User (e.g. $user->ynabAccounts()) there.
 *
 * @mixin Model
 */
trait BelongsToUser
{
    public static function bootBelongsToUser(): void
    {
        static::addGlobalScope('user', function (Builder $query): void {
            if (Auth::check()) {
                $query->where($query->getModel()->qualifyColumn('user_id'), Auth::id());
            }
        });

        static::creating(function (Model $model): void {
            if (! $model->getAttribute('user_id') && Auth::check()) {
                $model->setAttribute('user_id', Auth::id());
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
