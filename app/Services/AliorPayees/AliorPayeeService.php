<?php

namespace App\Services\AliorPayees;

use App\Models\Setting;
use App\Models\User;
use App\Services\Ynab\YnabClientFactory;
use App\Services\Ynab\YnabException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Alior's bank sync gets payees wrong ("Transakcja kartą", another merchant). This matches rows read from an Alior
 * screenshot to the YNAB transactions of the Alior account by amount + date, then renames the payee of matched ones
 * and creates the ones YNAB doesn't have yet.
 */
class AliorPayeeService
{
    public const ACTION_RENAME = 'rename';

    public const ACTION_OK = 'ok';

    public const ACTION_CREATE = 'create';

    /** Bank booking dates can lag the purchase date; match within this many days when the exact date has no hit. */
    public const DATE_TOLERANCE_DAYS = 3;

    public function __construct(private readonly YnabClientFactory $ynab) {}

    public function accountId(User $user): ?string
    {
        return $user->setting(Setting::ALIOR_YNAB_ACCOUNT);
    }

    /**
     * @param  list<AliorRow>  $rows
     * @return list<array{row: AliorRow, action: string, ynabId: string|null, ynabPayee: string|null, ynabDate: string|null}>
     *
     * @throws YnabException
     */
    public function preview(User $user, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $accountId = $this->accountId($user)
            ?? throw new YnabException(__('Choose the Alior account in Settings → YNAB.'));

        $since = min(array_map(fn (AliorRow $r): string => $r->date->toDateString(), $rows));
        $since = CarbonImmutable::parse($since)->subDays(self::DATE_TOLERANCE_DAYS)->toDateString();

        $candidates = array_values(array_filter(
            $this->ynab->forUser($user)->transactionsSince($user->ynabPlanId(), $accountId, $since),
            fn (array $t): bool => self::matchable($t),
        ));

        $matches = self::match($rows, $candidates);

        return array_map(function (AliorRow $row, ?array $tx): array {
            $payee = $tx === null ? null : (is_string($tx['payee_name'] ?? null) ? $tx['payee_name'] : null);

            return [
                'row' => $row,
                'action' => match (true) {
                    $tx === null => self::ACTION_CREATE,
                    AliorRow::normalize($payee) === AliorRow::normalize($row->merchant) => self::ACTION_OK,
                    default => self::ACTION_RENAME,
                },
                'ynabId' => $tx === null ? null : (string) $tx['id'],
                'ynabPayee' => $payee,
                'ynabDate' => $tx === null ? null : (string) $tx['date'],
            ];
        }, $rows, $matches);
    }

    /**
     * Pair each row with at most one YNAB transaction of the same amount, each YNAB transaction used once.
     * Pass 1 takes exact dates (preferring one whose payee already matches), pass 2 the closest date within the
     * tolerance, so a fallback never takes a transaction another row matches exactly.
     *
     * @param  list<AliorRow>  $rows
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array<string, mixed>|null>
     */
    public static function match(array $rows, array $candidates): array
    {
        $used = [];
        $result = array_fill(0, count($rows), null);

        foreach ($rows as $i => $row) {
            $best = null;
            foreach ($candidates as $j => $tx) {
                if (isset($used[$j]) || (int) $tx['amount'] !== $row->amount || $tx['date'] !== $row->date->toDateString()) {
                    continue;
                }
                if ($best === null || AliorRow::normalize($tx['payee_name'] ?? null) === AliorRow::normalize($row->merchant)) {
                    $best = $j;
                }
            }
            if ($best !== null) {
                $used[$best] = true;
                $result[$i] = $candidates[$best];
            }
        }

        foreach ($rows as $i => $row) {
            if ($result[$i] !== null) {
                continue;
            }
            $best = null;
            $bestDiff = PHP_INT_MAX;
            foreach ($candidates as $j => $tx) {
                if (isset($used[$j]) || (int) $tx['amount'] !== $row->amount) {
                    continue;
                }
                $diff = (int) abs($row->date->diffInDays(CarbonImmutable::parse((string) $tx['date'])->startOfDay()));
                if ($diff <= self::DATE_TOLERANCE_DAYS && $diff < $bestDiff) {
                    [$best, $bestDiff] = [$j, $diff];
                }
            }
            if ($best !== null) {
                $used[$best] = true;
                $result[$i] = $candidates[$best];
            }
        }

        return array_values($result);
    }

    /**
     * Rename payees of matched transactions and create the missing ones.
     *
     * @param  list<array{id: string, payee: string}>  $renames
     * @param  list<AliorRow>  $creates
     * @return array{renamed: int, created: int, alreadyInYnab: int}
     *
     * @throws YnabException
     */
    public function apply(User $user, array $renames, array $creates): array
    {
        $accountId = $this->accountId($user)
            ?? throw new YnabException(__('Choose the Alior account in Settings → YNAB.'));

        $client = $this->ynab->forUser($user);
        $planId = $user->ynabPlanId();
        $created = 0;
        $alreadyInYnab = 0;

        if ($renames !== []) {
            // YNAB resolves payee_name (existing payee or a new one) only when payee_id is null.
            $client->updateTransactions($planId, array_map(fn (array $r): array => [
                'id' => $r['id'],
                'payee_id' => null,
                'payee_name' => Str::limit($r['payee'], 200, ''),
            ], $renames));
        }

        if ($creates !== []) {
            $result = $client->createTransactions($planId, self::createPayloads($creates, $accountId));
            $created = count($result['transactions']);
            $alreadyInYnab = count($result['duplicate_import_ids']);
        }

        return ['renamed' => count($renames), 'created' => $created, 'alreadyInYnab' => $alreadyInYnab];
    }

    /**
     * Unapproved and uncleared, like card import. Identical rows (two equal purchases on one day) get an
     * occurrence suffix so their import_ids differ.
     *
     * @param  list<AliorRow>  $rows
     * @return list<array<string, mixed>>
     */
    public static function createPayloads(array $rows, string $accountId): array
    {
        $seen = [];

        return array_map(function (AliorRow $row) use (&$seen, $accountId): array {
            $base = $row->importId();
            $seen[$base] = ($seen[$base] ?? 0) + 1;

            return [
                'account_id' => $accountId,
                'date' => $row->date->toDateString(),
                'amount' => $row->amount,
                'payee_name' => Str::limit($row->merchant, 200, ''),
                'memo' => null,
                'cleared' => 'uncleared',
                'approved' => false,
                'import_id' => $seen[$base] === 1 ? $base : substr($base, 0, 34).':'.$seen[$base],
            ];
        }, $rows);
    }

    /**
     * Deleted rows, transfers and the unapproved half of a YNAB match can't be the bank's record of a purchase.
     *
     * @param  array<string, mixed>  $tx
     */
    private static function matchable(array $tx): bool
    {
        return empty($tx['deleted'])
            && empty($tx['transfer_account_id'])
            && ! (! empty($tx['matched_transaction_id']) && empty($tx['approved']))
            && isset($tx['id'], $tx['amount'], $tx['date']);
    }
}
