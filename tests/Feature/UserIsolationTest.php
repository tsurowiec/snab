<?php

use App\Models\CardTransaction;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use App\Models\YnabAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->alice = User::factory()->withYnabToken('alice-token')->create();
    $this->bob = User::factory()->withYnabToken('bob-token')->create();

    YnabAccount::factory()->for($this->alice)->create(['name' => 'Alice EUR']);
    $this->bobAccount = YnabAccount::factory()->for($this->bob)->create(['name' => 'Bob EUR']);
    $this->bob->setSetting(Setting::YNAB_PLAN_ID, 'bob-plan');
    $this->bob->exchangeRates()->create(['currency' => 'EUR', 'date' => '2026-01-15', 'rate' => 4.2, 'rate_date' => '2026-01-15']);
    CardTransaction::factory()->for($this->bob)->create(['description' => 'Bob coffee']);
});

it('scopes every model to the authenticated user', function () {
    $this->actingAs($this->alice);

    expect(YnabAccount::pluck('name')->all())->toBe(['Alice EUR'])
        ->and(Setting::pluck('key')->all())->toBe([Setting::YNAB_TOKEN])
        ->and($this->alice->ynabToken())->toBe('alice-token')
        ->and(ExchangeRate::count())->toBe(0)
        ->and(CardTransaction::count())->toBe(0)
        ->and($this->alice->ynabPlanId())->toBe('last-used');
});

it('assigns new records to the authenticated user', function () {
    $this->actingAs($this->alice);

    $account = YnabAccount::create(['ynab_id' => 'x', 'name' => 'New', 'currency' => 'USD']);

    expect($account->user_id)->toBe($this->alice->id);
});

it('returns 404 for another user\'s account', function () {
    fakeApis();

    // control: the owner can open it
    $this->actingAs($this->bob)
        ->get(route('conversions.show', $this->bobAccount))
        ->assertOk();

    $this->actingAs($this->alice)
        ->get(route('conversions.show', $this->bobAccount))
        ->assertNotFound();
});

it('does not list another user\'s accounts', function () {
    Livewire::withoutLazyLoading();
    fakeApis();

    Livewire::actingAs($this->alice)
        ->test('pages::tools.conversions.index')
        ->assertSee('Alice EUR')
        ->assertDontSee('Bob EUR');
});

it('cascades deletes from the user', function () {
    $this->bob->delete();

    expect(YnabAccount::withoutGlobalScopes()->where('user_id', $this->bob->id)->count())->toBe(0)
        ->and(Setting::withoutGlobalScopes()->where('user_id', $this->bob->id)->count())->toBe(0)
        ->and(ExchangeRate::withoutGlobalScopes()->where('user_id', $this->bob->id)->count())->toBe(0)
        ->and(CardTransaction::withoutGlobalScopes()->where('user_id', $this->bob->id)->count())->toBe(0);
});

it('calls YNAB with each user\'s own token', function () {
    Livewire::withoutLazyLoading();
    fakeApis();

    Livewire::actingAs($this->alice)->test('pages::tools.conversions.index');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer alice-token'));
    Http::assertNotSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer bob-token'));

    Livewire::withoutLazyLoading(); // applies to a single test() call only
    Livewire::actingAs($this->bob)->test('pages::tools.conversions.index');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer bob-token'));
});

it('does not show another user\'s card transactions', function () {
    Livewire::actingAs($this->alice)
        ->test('pages::tools.card-import.index')
        ->assertDontSee('Bob coffee');
});
