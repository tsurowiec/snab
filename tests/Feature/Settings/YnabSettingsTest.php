<?php

use App\Models\Setting;
use App\Models\User;
use App\Models\YnabAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->withYnabToken()->create();
    $this->actingAs($this->user);
});

function remoteAccounts(): array
{
    return [
        ['id' => 'acc-eur', 'name' => "🇪🇺  Revolut\n EUR", 'balance' => 123450, 'type' => 'checking', 'closed' => false, 'deleted' => false],
        ['id' => 'acc-czk', 'name' => 'Cash CZK', 'balance' => 0, 'type' => 'cash', 'closed' => false, 'deleted' => false],
        ['id' => 'acc-old', 'name' => 'Closed one', 'balance' => 0, 'type' => 'cash', 'closed' => true, 'deleted' => false],
    ];
}

it('renders the YNAB settings page', function () {
    fakeApis(accounts: remoteAccounts(), plans: [['id' => 'plan-1', 'name' => 'Home budget']]);

    $this->get(route('ynab.edit'))
        ->assertOk()
        ->assertSee('Home budget')
        ->assertSee('🇪🇺 Revolut EUR')
        ->assertDontSee('Closed one');
});

describe('token', function () {
    it('warns when the user has no token and makes no YNAB calls', function () {
        $this->user->setYnabToken(null);
        fakeApis();

        Livewire::test('pages::settings.ynab')->assertSee('No YNAB token set');
        Http::assertNothingSent();
    });

    it('verifies and stores the token encrypted', function () {
        $this->user->setYnabToken(null);
        fakeApis(plans: [['id' => 'plan-1', 'name' => 'Home budget']]);

        Livewire::test('pages::settings.ynab')
            ->set('token', '  new-secret-token ')
            ->call('saveToken')
            ->assertHasNoErrors()
            ->assertSet('token', '')
            ->assertSet('configured', true)
            ->assertSee('Home budget');

        $raw = $this->user->settings()->where('key', Setting::YNAB_TOKEN)->value('value');
        expect($raw)->not->toContain('new-secret-token')
            ->and($this->user->ynabToken())->toBe('new-secret-token');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer new-secret-token'));
    });

    it('does not store a token YNAB rejects', function () {
        $this->user->setYnabToken(null);
        Http::fake(['api.ynab.com/*' => Http::response(['error' => ['id' => '401', 'name' => 'unauthorized', 'detail' => 'Unauthorized']], 401)]);

        Livewire::test('pages::settings.ynab')
            ->set('token', 'bad-token')
            ->call('saveToken')
            ->assertHasErrors('token');

        expect($this->user->hasYnabToken())->toBeFalse();
    });

    it('never sends the stored token to the browser', function () {
        fakeApis();

        Livewire::test('pages::settings.ynab')
            ->assertSet('token', '')
            ->assertDontSee('test-token');
    });

    it('removes the token', function () {
        fakeApis();

        Livewire::test('pages::settings.ynab')->call('removeToken')->assertSet('configured', false);

        expect($this->user->fresh()->hasYnabToken())->toBeFalse();
    });
});

it('saves the selected budget for the current user', function () {
    fakeApis(plans: [['id' => 'plan-1', 'name' => 'Home budget']]);

    Livewire::test('pages::settings.ynab')
        ->set('planId', 'plan-1')
        ->call('savePlan')
        ->assertHasNoErrors();

    expect($this->user->ynabPlanId())->toBe('plan-1')
        ->and(Setting::withoutGlobalScopes()->first()->user_id)->toBe($this->user->id);
});

it('rejects an unknown budget id', function () {
    fakeApis(plans: [['id' => 'plan-1', 'name' => 'Home budget']]);

    Livewire::test('pages::settings.ynab')
        ->set('planId', 'nope')
        ->call('savePlan')
        ->assertHasErrors('planId');
});

it('saves enabled accounts with their currency', function () {
    fakeApis(accounts: remoteAccounts());

    Livewire::test('pages::settings.ynab')
        ->set('selection.acc-eur.enabled', true)
        ->set('selection.acc-eur.currency', 'EUR')
        ->set('selection.acc-czk.enabled', false)
        ->call('saveAccounts')
        ->assertHasNoErrors();

    expect($this->user->ynabAccounts()->get())->toHaveCount(1)
        ->and($this->user->ynabAccounts()->first())
        ->ynab_id->toBe('acc-eur')
        ->name->toBe('🇪🇺 Revolut EUR')
        ->currency->toBe('EUR')
        ->enabled->toBeTrue();
});

it('keeps but disables a previously enabled account', function () {
    YnabAccount::factory()->for($this->user)->create(['ynab_id' => 'acc-czk', 'currency' => 'CZK']);
    fakeApis(accounts: remoteAccounts());

    Livewire::test('pages::settings.ynab')
        ->assertSet('selection.acc-czk.enabled', true)
        ->assertSet('selection.acc-czk.currency', 'CZK')
        ->set('selection.acc-czk.enabled', false)
        ->call('saveAccounts');

    expect($this->user->ynabAccounts()->first()->enabled)->toBeFalse();
});

it('rejects unsupported currencies', function () {
    fakeApis(accounts: remoteAccounts());

    Livewire::test('pages::settings.ynab')
        ->set('selection.acc-eur.enabled', true)
        ->set('selection.acc-eur.currency', 'PLN')
        ->call('saveAccounts')
        ->assertHasErrors('selection.acc-eur.currency');
});

it('initialises the cache by storing the current server_knowledge (init-cache)', function () {
    $a = YnabAccount::factory()->for($this->user)->create();
    $b = YnabAccount::factory()->for($this->user)->create();
    fakeApis(serverKnowledge: 999);

    Livewire::test('pages::settings.ynab')->call('initCache', $a->id);
    expect($a->fresh()->server_knowledge)->toBe(999)
        ->and($b->fresh()->server_knowledge)->toBeNull();

    Livewire::test('pages::settings.ynab')->call('initCache');
    expect($b->fresh()->server_knowledge)->toBe(999);
});

it('resets the cache', function () {
    $a = YnabAccount::factory()->for($this->user)->create(['server_knowledge' => 5]);
    fakeApis();

    Livewire::test('pages::settings.ynab')->call('resetCache', $a->id);

    expect($a->fresh()->server_knowledge)->toBeNull();
});

it('refreshes account names from YNAB', function () {
    $a = YnabAccount::factory()->for($this->user)->create(['ynab_id' => 'acc-czk', 'name' => 'Old name']);
    fakeApis(accounts: remoteAccounts());

    Livewire::test('pages::settings.ynab')->call('refreshNames');

    expect($a->fresh()->name)->toBe('Cash CZK');
});

it('clears only the current user\'s rate cache', function () {
    $other = User::factory()->create();
    $this->user->exchangeRates()->create(['currency' => 'EUR', 'date' => '2026-01-15', 'rate' => 4.2, 'rate_date' => '2026-01-15']);
    $other->exchangeRates()->create(['currency' => 'EUR', 'date' => '2026-01-15', 'rate' => 4.2, 'rate_date' => '2026-01-15']);
    fakeApis();

    Livewire::test('pages::settings.ynab')->call('clearRates');

    expect($this->user->exchangeRates()->count())->toBe(0)
        ->and($other->exchangeRates()->count())->toBe(1);
});
