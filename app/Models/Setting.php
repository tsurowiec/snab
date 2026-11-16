<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-user key/value settings. Use $user->setting() / $user->setSetting().
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string|null $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'key', 'value'])]
class Setting extends Model
{
    use BelongsToUser;

    public const YNAB_PLAN_ID = 'ynab_plan_id';

    /** Stored encrypted — use $user->ynabToken() / $user->setYnabToken(). */
    public const YNAB_TOKEN = 'ynab_token';
}
