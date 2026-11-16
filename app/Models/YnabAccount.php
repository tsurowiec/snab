<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\YnabAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A YNAB account whose transactions are kept in a foreign currency and
 * converted to PLN. Replaces ynab-euro's YNAB_ACCOUNTS env var and the
 * account_names.json / server_knowledge.json caches.
 *
 * @property int $id
 * @property int $user_id
 * @property string $ynab_id
 * @property string $name
 * @property string $currency
 * @property bool $enabled
 * @property int|null $server_knowledge
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'ynab_id', 'name', 'currency', 'enabled', 'server_knowledge'])]
class YnabAccount extends Model
{
    use BelongsToUser;

    /** @use HasFactory<YnabAccountFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'server_knowledge' => 'integer',
        ];
    }
}
