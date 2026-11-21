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

    /** YNAB account id that Card import sends transactions to. */
    public const CARD_IMPORT_YNAB_ACCOUNT = 'card_import_ynab_account_id';

    /** YNAB account that Alior payees fixes payees in (and adds missing transactions to). */
    public const ALIOR_YNAB_ACCOUNT = 'alior_ynab_account_id';

    /** Stored encrypted — use $user->ynabToken() / $user->setYnabToken(). */
    public const YNAB_TOKEN = 'ynab_token';

    /** Stored encrypted — use $user->anthropicApiKey() / $user->setAnthropicApiKey(). */
    public const ANTHROPIC_API_KEY = 'anthropic_api_key';
}
