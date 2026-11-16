<?php

namespace App\Services\CardImport;

use App\Models\CardTransaction;
use App\Models\User;

/** Dedups extracted rows against what the user already stored, and stores the new ones. */
class CardImportService
{
    /**
     * Mark each row a duplicate if its hash is already stored for the user or appears earlier in the batch.
     *
     * @param  list<ExtractedTransaction>  $rows
     * @return list<array{transaction: ExtractedTransaction, duplicate: bool}>
     */
    public function preview(User $user, array $rows): array
    {
        $hashes = array_map(fn (ExtractedTransaction $t): string => $t->hash(), $rows);
        $stored = array_flip($user->cardTransactions()->whereIn('hash', $hashes)->pluck('hash')->all());
        $seen = [];
        $result = [];

        foreach ($rows as $i => $row) {
            $hash = $hashes[$i];
            $result[] = ['transaction' => $row, 'duplicate' => isset($stored[$hash]) || isset($seen[$hash])];
            $seen[$hash] = true;
        }

        return $result;
    }

    /**
     * Store rows that aren't stored yet. Safe against repeats and races thanks to the (user_id, hash) unique index.
     *
     * @param  list<ExtractedTransaction>  $rows
     * @return array{saved: int, skipped: int}
     */
    public function store(User $user, array $rows, string $source = 'revolut'): array
    {
        $now = now();
        $records = [];

        foreach ($rows as $row) {
            $records[$row->hash()] ??= [
                'user_id' => $user->id,
                'source' => $source,
                'occurred_at' => $row->occurredAt->format('Y-m-d H:i:00'),
                'description' => $row->description,
                'amount' => $row->amount,
                'currency' => $row->currency,
                'original_amount' => $row->originalAmount,
                'original_currency' => $row->originalCurrency,
                'hash' => $row->hash(),
                'status' => CardTransaction::STATUS_NEW,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $saved = $records === [] ? 0 : CardTransaction::withoutGlobalScopes()->insertOrIgnore(array_values($records));

        return ['saved' => $saved, 'skipped' => count($rows) - $saved];
    }
}
