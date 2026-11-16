<?php

use App\Models\User;
use App\Models\YnabAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Livewire::withoutLazyLoading();
    $this->travelTo('2026-01-20 15:00');
    $this->user = User::factory()->withYnabToken()->create();
    $this->actingAs($this->user);
});

it('lists enabled accounts with pending counts and today\'s rate', function () {
    YnabAccount::factory()->for($this->user)->create(['name' => '🇪🇺 Revolut', 'currency' => 'EUR']);
    YnabAccount::factory()->for($this->user)->disabled()->create(['name' => 'Hidden account']);
    fakeApis(transactions: [ynabTx(), ynabTx()], nbpRates: ['EUR' => ['2026-01-20' => 4.2531]]);

    Livewire::test('pages::tools.conversions.index')
        ->assertSee('🇪🇺 Revolut')
        ->assertSee('[EUR]')
        ->assertSee('2 pending')
        ->assertSee('4.2531 PLN · Jan 20')
        ->assertDontSee('Hidden account');
});

it('renders the page over HTTP', function () {
    fakeApis();

    $this->get(route('conversions.index'))->assertOk()->assertSee('Conversions');
});

it('asks for a token when the user has none', function () {
    $this->user->setYnabToken(null);
    YnabAccount::factory()->for($this->user)->create(['name' => 'Revolut']);
    fakeApis(nbpRates: ['EUR' => ['2026-01-20' => 4.25]]);

    Livewire::test('pages::tools.conversions.index')
        ->assertSee('No YNAB token set')
        ->assertSee('0 pending');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.ynab.com'));
});

it('points to settings when no accounts are configured', function () {
    fakeApis();

    Livewire::test('pages::tools.conversions.index')
        ->assertSee('No accounts configured yet.')
        ->assertSee(route('ynab.edit'));
});

it('shows YNAB errors instead of crashing', function () {
    YnabAccount::factory()->for($this->user)->create();
    Http::fake(['api.ynab.com/*' => Http::response(['error' => ['id' => '401', 'name' => 'unauthorized', 'detail' => 'Unauthorized']], 401), '*' => Http::response([], 404)]);

    Livewire::test('pages::tools.conversions.index')->assertSee('YNAB API error (401): Unauthorized');
});

it('shows pending transactions of an account', function () {
    $account = YnabAccount::factory()->for($this->user)->create(['name' => 'Revolut', 'currency' => 'EUR']);
    fakeApis(
        transactions: [ynabTx(['id' => 'a', 'payee_name' => 'Bakery', 'amount' => -10000])],
        nbpRates: ['EUR' => ['2026-01-15' => 4.25, '2026-01-20' => 4.30]],
    );

    Livewire::test('pages::tools.conversions.show', ['account' => $account])
        ->assertSet('transactions.0.id', 'a')
        ->assertSet('transactions.0.convertedAmount', -42500)
        ->assertSet('todayRate', 4.30)
        ->assertSee('Revolut')
        ->assertSee('1.00 EUR → 4.3000 PLN');
});

it('converts the selected transactions and ignores the rest', function () {
    $account = YnabAccount::factory()->for($this->user)->create(['ynab_id' => 'acc-1', 'currency' => 'EUR']);
    fakeApis(
        transactions: [ynabTx(['id' => 'a']), ynabTx(['id' => 'b'])],
        serverKnowledge: 77,
        nbpRates: ['EUR' => ['2026-01-15' => 4.0]],
    );

    Livewire::test('pages::tools.conversions.show', ['account' => $account])
        ->call('convert', ['a'])
        ->assertSet('result', ['converted' => 1, 'ignored' => 1, 'dryRun' => false])
        ->assertSee('Done!')
        ->assertSee('1 converted, 1 ignored.');

    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
        && collect($r['transactions'])->pluck('memo', 'id')->all() === ['a' => 'TX -10.00EUR @ 4.0000 (2026-01-15)', 'b' => 'TX ignored']);
    expect($account->fresh()->server_knowledge)->toBe(77);
});

it('shows an empty state when nothing is pending', function () {
    $account = YnabAccount::factory()->for($this->user)->create();
    fakeApis(transactions: [ynabTx(['memo' => 'TX ignored'])]);

    Livewire::test('pages::tools.conversions.show', ['account' => $account])->assertSee('No unprocessed transactions.');
});
