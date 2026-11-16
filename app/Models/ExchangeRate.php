<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cached NBP mid-rate. `date` is the requested (transaction) date,
 * `rate_date` the NBP table date actually used (may be earlier, e.g. weekends).
 * Dates are stored as Y-m-d strings.
 *
 * @property int $id
 * @property int $user_id
 * @property string $currency
 * @property string $date
 * @property float $rate
 * @property string $rate_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'currency', 'date', 'rate', 'rate_date'])]
class ExchangeRate extends Model
{
    use BelongsToUser;

    protected function casts(): array
    {
        return [
            'rate' => 'float',
        ];
    }
}
