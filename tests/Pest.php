<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        Http::preventStrayRequests();
    })
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * A YNAB TransactionDetail-shaped array (amounts in milliunits).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ynabTx(array $overrides = []): array
{
    return array_merge([
        'id' => (string) Str::uuid(),
        'date' => '2026-01-15',
        'amount' => -10000,
        'memo' => null,
        'payee_name' => 'Shop',
        'approved' => true,
        'deleted' => false,
        'matched_transaction_id' => null,
    ], $overrides);
}

/**
 * Fake both the YNAB API and the NBP API.
 *
 * @param  list<array<string, mixed>>  $transactions  returned for any account's transactions endpoint
 * @param  array<string, array<string, float>>  $nbpRates  ['EUR' => ['2026-01-15' => 4.25, ...]]
 * @param  list<array<string, mixed>>  $accounts  returned by /plans/{id}/accounts
 * @param  list<array<string, mixed>>  $plans  returned by /plans
 */
function fakeApis(
    array $transactions = [],
    int $serverKnowledge = 100,
    array $nbpRates = [],
    array $accounts = [],
    array $plans = [],
): void {
    Http::fake(function (Request $request) use ($transactions, $serverKnowledge, $nbpRates, $accounts, $plans) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (str_contains($request->url(), 'api.nbp.pl')) {
            preg_match('#/rates/a/(\w+)/([\d-]+)/([\d-]+)/#', $path, $m);
            $rates = collect($nbpRates[strtoupper($m[1])] ?? [])
                ->filter(fn ($mid, $date) => $date >= $m[2] && $date <= $m[3])
                ->sortKeys()
                ->map(fn ($mid, $date) => ['effectiveDate' => $date, 'mid' => $mid])
                ->values();

            return $rates->isEmpty()
                ? Http::response('404 NotFound - Not Found - Brak danych', 404)
                : Http::response(['table' => 'A', 'code' => $m[1], 'rates' => $rates->all()]);
        }

        return match (true) {
            $request->method() === 'PATCH' && str_ends_with($path, '/transactions') => Http::response(['data' => ['transaction_ids' => [], 'server_knowledge' => $serverKnowledge + 1]]),
            (bool) preg_match('#/accounts/[^/]+/transactions$#', $path) => Http::response(['data' => ['transactions' => $transactions, 'server_knowledge' => $serverKnowledge]]),
            (bool) preg_match('#/plans/[^/]+/accounts$#', $path) => Http::response(['data' => ['accounts' => $accounts, 'server_knowledge' => $serverKnowledge]]),
            (bool) preg_match('#/plans$#', $path) => Http::response(['data' => ['plans' => $plans, 'default_plan' => null]]),
            default => Http::response(['error' => ['id' => '404', 'name' => 'not_found', 'detail' => 'Unexpected request in test: '.$path]], 404),
        };
    });
}
