<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Carbon\CarbonInterface;
use Database\Factories\CardTransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A card transaction read from a bank screenshot (Card Import tool).
 * Unique per user by `hash` so overlapping screenshots never import a row twice.
 *
 * @property int $id
 * @property int $user_id
 * @property string $source
 * @property Carbon $occurred_at
 * @property string $description
 * @property int $amount
 * @property string $currency
 * @property int|null $original_amount
 * @property string|null $original_currency
 * @property string $hash
 * @property string $status
 * @property string|null $ynab_transaction_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'source', 'occurred_at', 'description', 'amount', 'currency', 'original_amount', 'original_currency', 'hash', 'status', 'ynab_transaction_id'])]
class CardTransaction extends Model
{
    use BelongsToUser;

    /** @use HasFactory<CardTransactionFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_RECORDED = 'recorded';

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'amount' => 'integer',
            'original_amount' => 'integer',
        ];
    }

    /** YNAB import_id (max 36 chars): YNAB itself rejects a second transaction with the same id in an account. */
    public function importId(): string
    {
        return 'SNAB:'.substr($this->hash, 0, 31);
    }

    /**
     * Dedup key: minute-precision date/time, amount, currency and a normalized description
     * (lowercased, trimmed, whitespace collapsed), so small OCR spacing/case differences still match.
     */
    public static function hashFor(CarbonInterface $occurredAt, int $amount, string $currency, string $description): string
    {
        $normalized = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $description)));

        return hash('sha256', implode('|', [
            $occurredAt->format('Y-m-d H:i'),
            $amount,
            strtoupper($currency),
            $normalized,
        ]));
    }
}
