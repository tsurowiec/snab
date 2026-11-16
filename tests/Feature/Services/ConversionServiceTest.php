<?php

use App\Models\Setting;
use App\Models\YnabAccount;
use App\Services\Conversions\ConversionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->account = YnabAccount::factory()->create(['ynab_id' => 'acc-1', 'currency' => 'EUR', 'server_knowledge' => 50]);
    $this->account->user->setSetting(Setting::YNAB_PLAN_ID, 'plan-1');
    $this->service = app(ConversionService::class);
});

describe('amount and memo formatting', function () {
    it('rounds like JS Math.round, including negative half values', function (int $amount, float $rate, int $expected) {
        expect(ConversionService::convertAmount($amount, $rate))->toBe($expected);
    })->with([
        'outflow' => [-42500, 4.2531, -180757],
        'inflow' => [10000, 4.25, 42500],
        'negative half rounds up' => [-5, 0.5, -2],
        'positive half rounds up' => [5, 0.5, 3],
    ]);

    it('appends the conversion stamp to the memo', function () {
        expect(ConversionService::convertedMemo(null, -42500, 'EUR', 4.2531, '2024-01-15'))
            ->toBe('TX -42.50EUR @ 4.2531 (2024-01-15)')
            ->and(ConversionService::convertedMemo('Lunch', 42500, 'EUR', 4.25, '2024-01-15'))
            ->toBe('Lunch TX 42.50EUR @ 4.2500 (2024-01-15)');
    });

    it('appends the ignored stamp to the memo', function () {
        expect(ConversionService::ignoredMemo(null))->toBe('TX ignored')
            ->and(ConversionService::ignoredMemo('Lunch'))->toBe('Lunch TX ignored');
    });
});

it('lists pending transactions sorted by date with per-date rates', function () {
    fakeApis(
        transactions: [
            ynabTx(['id' => 'b', 'date' => '2026-01-16', 'amount' => -20000]),
            ynabTx(['id' => 'a', 'date' => '2026-01-15', 'amount' => -10000, 'memo' => 'Lunch']),
            ynabTx(['id' => 'done', 'memo' => 'TX ignored']),
        ],
        nbpRates: ['EUR' => ['2026-01-15' => 4.0, '2026-01-16' => 4.5]],
    );

    $pending = $this->service->pending($this->account);

    expect($pending)->toHaveCount(2)
        ->and($pending[0]->toArray())->toBe([
            'id' => 'a', 'date' => '2026-01-15', 'amount' => -10000, 'payeeName' => 'Shop', 'memo' => 'Lunch',
            'rate' => 4.0, 'rateDate' => '2026-01-15', 'convertedAmount' => -40000,
        ])
        ->and($pending[1]->id)->toBe('b')
        ->and($pending[1]->convertedAmount)->toBe(-90000);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/plans/plan-1/accounts/acc-1/transactions')
        && $r['last_knowledge_of_server'] == 50);
});

it('leaves out transactions without an NBP rate', function () {
    fakeApis(
        transactions: [ynabTx(['id' => 'a', 'date' => '2026-01-15']), ynabTx(['id' => 'b', 'date' => '2025-06-01'])],
        nbpRates: ['EUR' => ['2026-01-15' => 4.0]],
    );

    expect($this->service->pending($this->account))->toHaveCount(1);
});

it('counts pending transactions', function () {
    fakeApis(transactions: [ynabTx(), ynabTx(), ynabTx(['memo' => 'x TX ignored']), ynabTx(['deleted' => true])]);

    expect($this->service->pendingCount($this->account))->toBe(2);
});

it('converts selected and ignores unselected transactions, then stores server_knowledge', function () {
    fakeApis(
        transactions: [
            ynabTx(['id' => 'a', 'amount' => -10000, 'memo' => 'Lunch']),
            ynabTx(['id' => 'b', 'amount' => -5000]),
            ynabTx(['id' => 'not-selected']),
        ],
        serverKnowledge: 123,
        nbpRates: ['EUR' => ['2026-01-15' => 4.2531]],
    );

    $result = $this->service->apply($this->account, ['a' => true, 'b' => false]);

    expect($result->converted)->toBe(1)
        ->and($result->ignored)->toBe(1)
        ->and($result->dryRun)->toBeFalse();

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && str_ends_with($r->url(), '/plans/plan-1/transactions')
        && $r['transactions'] === [
            ['id' => 'a', 'amount' => -42531, 'memo' => 'Lunch TX -10.00EUR @ 4.2531 (2026-01-15)'],
            ['id' => 'b', 'amount' => -5000, 'memo' => 'TX ignored'],
        ]);

    expect($this->account->fresh()->server_knowledge)->toBe(123);
});

it('does not post or store server_knowledge on a dry run', function () {
    fakeApis(transactions: [ynabTx(['id' => 'a'])], serverKnowledge: 123, nbpRates: ['EUR' => ['2026-01-15' => 4.0]]);

    $result = $this->service->apply($this->account, ['a' => true], dryRun: true);

    expect($result->dryRun)->toBeTrue()
        ->and($result->updates)->toHaveCount(1);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
    expect($this->account->fresh()->server_knowledge)->toBe(50);
});

it('never re-processes transactions that already carry the TX marker', function () {
    fakeApis(transactions: [ynabTx(['id' => 'a', 'memo' => 'TX ignored'])], nbpRates: ['EUR' => ['2026-01-15' => 4.0]]);

    $result = $this->service->apply($this->account, ['a' => true]);

    expect($result->updates)->toBe([]);
    Http::assertNotSent(fn (Request $r) => $r->method() === 'PATCH');
});
