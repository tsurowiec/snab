<?php

namespace App\Services\Conversions;

use App\Models\YnabAccount;
use App\Services\Rates\NbpRateService;
use App\Services\Ynab\YnabClient;

/**
 * Converts foreign-currency YNAB transactions to PLN using the NBP mid-rate
 * for each transaction's own date.
 *
 *  - Converted: amount = round(amount * rate) (milliunits), memo gets
 *    "TX 42.50EUR @ 4.2531 (2024-01-15)" appended.
 *  - Ignored:   amount unchanged, memo gets "TX ignored" appended.
 */
class ConversionService
{
    public function __construct(
        private readonly YnabClient $ynab,
        private readonly NbpRateService $rates,
    ) {}

    public function pendingCount(YnabAccount $account): int
    {
        return count(array_filter($this->fetch($account)['transactions'], PendingFilter::isPending(...)));
    }

    /**
     * Pending transactions sorted by date, each with its rate. Transactions
     * whose date has no NBP rate are left out.
     *
     * @return list<PendingTransaction>
     */
    public function pending(YnabAccount $account): array
    {
        $pending = array_values(array_filter($this->fetch($account)['transactions'], PendingFilter::isPending(...)));
        usort($pending, fn (array $a, array $b): int => strcmp($a['date'], $b['date']));

        $rates = $this->ratesByDate($account, $pending);

        $result = [];
        foreach ($pending as $t) {
            if (! isset($rates[$t['date']])) {
                continue;
            }
            ['rate' => $rate, 'rateDate' => $rateDate] = $rates[$t['date']];
            $result[] = new PendingTransaction(
                id: $t['id'],
                date: $t['date'],
                amount: (int) $t['amount'],
                payeeName: $t['payee_name'] ?? null,
                memo: $t['memo'] ?? null,
                rate: $rate,
                rateDate: $rateDate,
                convertedAmount: self::convertAmount((int) $t['amount'], $rate),
            );
        }

        return $result;
    }

    /**
     * @param  array<string, bool>  $selections  transaction id => convert (true) / ignore (false)
     */
    public function apply(YnabAccount $account, array $selections, bool $dryRun = false): ConversionResult
    {
        $data = $this->fetch($account);

        $toUpdate = array_values(array_filter(
            $data['transactions'],
            fn (array $t): bool => array_key_exists($t['id'], $selections) && PendingFilter::isPending($t),
        ));

        $rates = $this->ratesByDate($account, $toUpdate);

        $updates = [];
        $converted = 0;
        $ignored = 0;
        foreach ($toUpdate as $t) {
            if (! isset($rates[$t['date']])) {
                continue;
            }
            $amount = (int) $t['amount'];
            $memo = $t['memo'] ?? null;

            if ($selections[$t['id']]) {
                ['rate' => $rate, 'rateDate' => $rateDate] = $rates[$t['date']];
                $updates[] = [
                    'id' => $t['id'],
                    'amount' => self::convertAmount($amount, $rate),
                    'memo' => self::convertedMemo($memo, $amount, $account->currency, $rate, $rateDate),
                ];
                $converted++;
            } else {
                $updates[] = ['id' => $t['id'], 'amount' => $amount, 'memo' => self::ignoredMemo($memo)];
                $ignored++;
            }
        }

        if (! $dryRun) {
            if ($updates !== []) {
                $this->ynab->updateTransactions($account->user->ynabPlanId(), $updates);
            }
            $account->update(['server_knowledge' => $data['server_knowledge']]);
        }

        return new ConversionResult($converted, $ignored, $dryRun, $updates);
    }

    /** Same as JS Math.round (half rounds towards +∞), which matters for negative outflows. */
    public static function convertAmount(int $amount, float $rate): int
    {
        return (int) floor($amount * $rate + 0.5);
    }

    public static function convertedMemo(?string $memo, int $amount, string $currency, float $rate, string $rateDate): string
    {
        $suffix = sprintf(
            '%s %s%s @ %s (%s)',
            PendingFilter::MARKER,
            number_format($amount / 1000, 2, '.', ''),
            $currency,
            number_format($rate, 4, '.', ''),
            $rateDate,
        );

        return filled($memo) ? "{$memo} {$suffix}" : $suffix;
    }

    public static function ignoredMemo(?string $memo): string
    {
        $suffix = PendingFilter::MARKER.' ignored';

        return filled($memo) ? "{$memo} {$suffix}" : $suffix;
    }

    /**
     * @return array{transactions: list<array<string, mixed>>, server_knowledge: int}
     */
    private function fetch(YnabAccount $account): array
    {
        return $this->ynab->transactionsByAccount(
            $account->user->ynabPlanId(),
            $account->ynab_id,
            $account->server_knowledge,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return array<string, array{rate: float, rateDate: string}>
     */
    private function ratesByDate(YnabAccount $account, array $transactions): array
    {
        $rates = [];
        foreach (array_unique(array_column($transactions, 'date')) as $date) {
            $rate = $this->rates->rateFor($account->user, $account->currency, $date);
            if ($rate !== null) {
                $rates[$date] = $rate;
            }
        }

        return $rates;
    }
}
