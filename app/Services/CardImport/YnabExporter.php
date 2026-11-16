<?php

namespace App\Services\CardImport;

use App\Models\CardTransaction;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ynab\YnabClientFactory;
use App\Services\Ynab\YnabException;
use Illuminate\Support\Str;

/**
 * Sends the user's not-yet-recorded card transactions to the YNAB account chosen in Settings → YNAB.
 * They are created unapproved and uncleared, as new imports to review in YNAB. Each one carries an
 * import_id derived from the dedup hash, so YNAB never creates the same transaction twice either.
 */
class YnabExporter
{
    private const CHUNK = 100;

    public function __construct(private readonly YnabClientFactory $ynab) {}

    public function accountId(User $user): ?string
    {
        return $user->setting(Setting::CARD_IMPORT_YNAB_ACCOUNT);
    }

    public function pendingCount(User $user): int
    {
        return $user->cardTransactions()->where('status', CardTransaction::STATUS_NEW)->count();
    }

    /**
     * @return array{sent: int, alreadyInYnab: int}
     *
     * @throws YnabException
     */
    public function export(User $user): array
    {
        $accountId = $this->accountId($user)
            ?? throw new YnabException(__('Choose the YNAB account for card transactions in Settings → YNAB.'));

        $client = $this->ynab->forUser($user);
        $planId = $user->ynabPlanId();
        $sent = 0;
        $alreadyInYnab = 0;

        $pending = $user->cardTransactions()
            ->where('status', CardTransaction::STATUS_NEW)
            ->orderBy('occurred_at')
            ->get();

        foreach ($pending->chunk(self::CHUNK) as $chunk) {
            /** @var array<string, CardTransaction> $byImportId */
            $byImportId = $chunk->keyBy(fn (CardTransaction $t): string => $t->importId())->all();

            $result = $client->createTransactions($planId, array_values(array_map(
                fn (CardTransaction $t): array => self::payload($t, $accountId),
                $byImportId,
            )));

            foreach ($result['transactions'] as $created) {
                $tx = $byImportId[$created['import_id'] ?? ''] ?? null;
                $tx?->update(['status' => CardTransaction::STATUS_RECORDED, 'ynab_transaction_id' => $created['id'] ?? null]);
                $sent += $tx ? 1 : 0;
            }

            foreach ($result['duplicate_import_ids'] as $importId) {
                ($byImportId[$importId] ?? null)?->update(['status' => CardTransaction::STATUS_RECORDED]);
                $alreadyInYnab++;
            }
        }

        return ['sent' => $sent, 'alreadyInYnab' => $alreadyInYnab];
    }

    /** @return array<string, mixed> */
    public static function payload(CardTransaction $t, string $accountId): array
    {
        $memo = $t->occurred_at->format('H:i');
        if ($t->original_amount !== null && $t->original_currency !== null) {
            $memo .= ' · '.number_format($t->original_amount / 1000, 2, '.', '').' '.$t->original_currency;
        }

        return [
            'account_id' => $accountId,
            'date' => $t->occurred_at->toDateString(),
            'amount' => $t->amount,
            'payee_name' => Str::limit($t->description, 200, ''),
            'memo' => $memo,
            'cleared' => 'uncleared',
            'approved' => false,
            'import_id' => $t->importId(),
        ];
    }
}
