<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\AliorPayees\AliorPayeeService;
use App\Services\AliorPayees\AliorRow;
use App\Services\Ynab\YnabException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function aliorRow(string $date, string $merchant, int $amount): AliorRow
{
    return AliorRow::fromArray(compact('date', 'merchant', 'amount'));
}

beforeEach(function () {
    $this->user = User::factory()->withYnabToken()->create();
    $this->user->setSetting(Setting::ALIOR_YNAB_ACCOUNT, 'acc-alior');
    $this->service = app(AliorPayeeService::class);
});

it('renames, keeps correct payees and adds missing transactions', function () {
    fakeApis(transactions: [
        ynabTx(['id' => 'a', 'date' => '2026-11-20', 'amount' => -42500, 'payee_name' => 'Transakcja kartą']),
        ynabTx(['id' => 'b', 'date' => '2026-11-19', 'amount' => -12990, 'payee_name' => 'ŻABKA']),
    ]);

    $preview = $this->service->preview($this->user, [
        aliorRow('2026-11-20', 'Lidl', -42500),
        aliorRow('2026-11-19', 'Żabka', -12990),
        aliorRow('2026-11-18', 'Orlen', -250000),
    ]);

    expect(array_column($preview, 'action'))->toBe(['rename', 'ok', 'create'])
        ->and($preview[0]['ynabId'])->toBe('a')
        ->and($preview[0]['ynabPayee'])->toBe('Transakcja kartą')
        ->and($preview[2]['ynabId'])->toBeNull();

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/accounts/acc-alior/transactions')
        && str_contains($r->url(), 'since_date=2026-11-15'));
});

it('falls back to the closest date within 3 days without stealing exact matches', function () {
    fakeApis(transactions: [
        ynabTx(['id' => 'exact', 'date' => '2026-11-20', 'amount' => -10000, 'payee_name' => 'X']),
        ynabTx(['id' => 'late', 'date' => '2026-11-22', 'amount' => -10000, 'payee_name' => 'X']),
        ynabTx(['id' => 'far', 'date' => '2026-11-26', 'amount' => -20000, 'payee_name' => 'X']),
    ]);

    $preview = $this->service->preview($this->user, [
        aliorRow('2026-11-19', 'Cafe', -10000), // ±3 days: both candidates, but "exact" belongs to the next row
        aliorRow('2026-11-20', 'Bar', -10000),
        aliorRow('2026-11-20', 'Shop', -20000), // 6 days off → not a match
    ]);

    expect(array_column($preview, 'ynabId'))->toBe(['late', 'exact', null])
        ->and($preview[0]['ynabDate'])->toBe('2026-11-22');
});

it('matches two equal purchases to two different transactions', function () {
    fakeApis(transactions: [
        ynabTx(['id' => 'one', 'date' => '2026-11-20', 'amount' => -900, 'payee_name' => 'Transakcja kartą']),
        ynabTx(['id' => 'two', 'date' => '2026-11-20', 'amount' => -900, 'payee_name' => 'Transakcja kartą']),
    ]);

    $preview = $this->service->preview($this->user, [aliorRow('2026-11-20', 'Kawa', -900), aliorRow('2026-11-20', 'Kawa', -900)]);

    expect(array_column($preview, 'ynabId'))->toBe(['one', 'two']);
});

it('ignores deleted transactions, transfers and the unapproved half of a match', function () {
    fakeApis(transactions: [
        ynabTx(['id' => 'deleted', 'date' => '2026-11-20', 'amount' => -100, 'deleted' => true]),
        ynabTx(['id' => 'transfer', 'date' => '2026-11-20', 'amount' => -100, 'transfer_account_id' => 'acc-other']),
        ynabTx(['id' => 'matched', 'date' => '2026-11-20', 'amount' => -100, 'matched_transaction_id' => 'x', 'approved' => false]),
    ]);

    expect($this->service->preview($this->user, [aliorRow('2026-11-20', 'A', -100)])[0]['action'])->toBe('create');
});

it('patches only the payee and creates missing rows unapproved', function () {
    fakeApis(duplicateImportIds: [aliorRow('2026-11-18', 'Orlen', -250000)->importId()]);

    $result = $this->service->apply($this->user, [['id' => 'a', 'payee' => 'Lidl']], [
        aliorRow('2026-11-18', 'Orlen', -250000),
        aliorRow('2026-11-17', 'Kawa', -900),
        aliorRow('2026-11-17', 'Kawa', -900),
    ]);

    expect($result)->toBe(['renamed' => 1, 'created' => 2, 'alreadyInYnab' => 1]);

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && $r['transactions'] === [['id' => 'a', 'payee_id' => null, 'payee_name' => 'Lidl']]);

    Http::assertSent(function (Request $r) {
        if ($r->method() !== 'POST') {
            return false;
        }
        $tx = $r['transactions'];
        $ids = array_column($tx, 'import_id');

        return count($tx) === 3
            && $tx[1] === ['account_id' => 'acc-alior', 'date' => '2026-11-17', 'amount' => -900, 'payee_name' => 'Kawa', 'memo' => null, 'cleared' => 'uncleared', 'approved' => false, 'import_id' => $ids[1]]
            && count(array_unique($ids)) === 3
            && max(array_map('strlen', $ids)) <= 36;
    });
});

it('sends nothing when there is nothing to do', function () {
    Http::fake();

    expect($this->service->apply($this->user, [], []))->toBe(['renamed' => 0, 'created' => 0, 'alreadyInYnab' => 0]);
    Http::assertNothingSent();
});

it('requires the Alior account', function () {
    $this->user->settings()->where('key', Setting::ALIOR_YNAB_ACCOUNT)->delete();

    expect(fn () => $this->service->preview($this->user, [aliorRow('2026-11-20', 'A', -1)]))
        ->toThrow(YnabException::class, 'Alior account');
});
