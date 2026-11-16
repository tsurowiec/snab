<?php

namespace App\Services\Conversions;

class PendingFilter
{
    /** Memo marker for transactions that were already processed (converted or ignored). */
    public const MARKER = 'TX';

    /**
     * A transaction awaits conversion if it has no TX marker in its memo.
     * The unapproved half of a YNAB match (the bank import paired with a
     * manually entered transaction) is skipped so the pair isn't converted twice.
     *
     * @param  array<string, mixed>  $transaction
     */
    public static function isPending(array $transaction): bool
    {
        if (! empty($transaction['deleted'])) {
            return false;
        }

        if (str_contains((string) ($transaction['memo'] ?? ''), self::MARKER)) {
            return false;
        }

        if (! empty($transaction['matched_transaction_id']) && empty($transaction['approved'])) {
            return false;
        }

        return true;
    }
}
