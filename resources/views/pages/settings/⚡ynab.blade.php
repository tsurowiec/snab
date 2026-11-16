<?php

use App\Models\Setting;
use App\Models\YnabAccount;
use App\Services\Ynab\YnabClient;
use App\Services\Ynab\YnabException;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * GUI replacement for ynab-euro's CLI helpers:
 *  - list-plans    → budget picker
 *  - list-accounts → account table with enable toggle + currency
 *  - init-cache    → "Skip existing transactions" (store current server_knowledge)
 */
new #[Title('YNAB settings')] class extends Component {
    /** NBP table A currencies offered in the currency picker. */
    public const CURRENCIES = ['EUR', 'USD', 'CHF', 'GBP', 'CZK', 'HUF', 'SEK', 'NOK', 'DKK', 'JPY', 'CAD', 'AUD', 'UAH', 'RON', 'BGN', 'TRY', 'ISK', 'CNY', 'HKD', 'NZD', 'SGD', 'MXN', 'BRL', 'ZAR', 'KRW', 'INR', 'ILS', 'THB', 'IDR', 'MYR', 'PHP', 'CLP', 'XDR'];

    public bool $configured = true;

    public ?string $error = null;

    public string $planId = 'last-used';

    /** @var list<array{id: string, name: string}> */
    public array $plans = [];

    /** YNAB accounts of the selected plan: @var list<array{id: string, name: string, balance: int, type: string}> */
    public array $remoteAccounts = [];

    /** Form state keyed by YNAB account id: @var array<string, array{enabled: bool, currency: string}> */
    public array $selection = [];

    public function mount(YnabClient $ynab): void
    {
        $this->configured = $ynab->isConfigured();
        $this->planId = Auth::user()->ynabPlanId();

        if ($this->configured) {
            $this->loadRemote();
        }
    }

    public function loadRemote(): void
    {
        $ynab = app(YnabClient::class);
        $this->error = null;

        try {
            $this->plans = array_map(
                fn (array $p): array => ['id' => $p['id'], 'name' => $p['name']],
                $ynab->plans(),
            );

            $this->remoteAccounts = collect($ynab->accounts($this->planId))
                ->reject(fn (array $a): bool => ! empty($a['closed']) || ! empty($a['deleted']))
                ->map(fn (array $a): array => [
                    'id' => $a['id'],
                    'name' => self::cleanName($a['name']),
                    'balance' => (int) $a['balance'],
                    'type' => (string) $a['type'],
                ])
                ->values()
                ->all();
        } catch (YnabException $e) {
            $this->error = $e->getMessage();
            $this->remoteAccounts = [];
        }

        $local = Auth::user()->ynabAccounts()->get()->keyBy('ynab_id');
        $this->selection = [];
        foreach ($this->remoteAccounts as $a) {
            $this->selection[$a['id']] = [
                'enabled' => (bool) $local->get($a['id'])?->enabled,
                'currency' => $local->get($a['id'])?->currency ?? 'EUR',
            ];
        }
    }

    public function savePlan(): void
    {
        $allowed = ['last-used', ...array_column($this->plans, 'id')];
        $this->validate(['planId' => ['required', 'string', 'in:'.implode(',', $allowed)]]);

        Auth::user()->setSetting(Setting::YNAB_PLAN_ID, $this->planId);
        $this->loadRemote();

        Flux::toast(variant: 'success', text: __('Budget saved.'));
    }

    public function saveAccounts(): void
    {
        $this->validate([
            'selection.*.enabled' => ['boolean'],
            'selection.*.currency' => ['required', 'string', 'in:'.implode(',', self::CURRENCIES)],
        ]);

        $user = Auth::user();
        $names = array_column($this->remoteAccounts, 'name', 'id');

        foreach ($this->selection as $ynabId => $row) {
            $existing = $user->ynabAccounts()->where('ynab_id', $ynabId)->first();
            if (! $row['enabled'] && ! $existing) {
                continue;
            }

            $user->ynabAccounts()->updateOrCreate(
                ['ynab_id' => $ynabId],
                ['name' => $names[$ynabId] ?? $existing?->name ?? $ynabId, 'currency' => $row['currency'], 'enabled' => (bool) $row['enabled']],
            );
        }

        Flux::toast(variant: 'success', text: __('Accounts saved.'));
    }

    /** init-cache: remember the current server_knowledge so only newer transactions show up. */
    public function initCache(?int $accountId = null): void
    {
        $user = Auth::user();
        $ynab = app(YnabClient::class);

        $accounts = $user->ynabAccounts()
            ->when($accountId, fn ($q) => $q->whereKey($accountId))
            ->get();

        try {
            foreach ($accounts as $account) {
                $data = $ynab->transactionsByAccount($user->ynabPlanId(), $account->ynab_id);
                $account->update(['server_knowledge' => $data['server_knowledge']]);
            }
        } catch (YnabException $e) {
            $this->error = $e->getMessage();

            return;
        }

        Flux::toast(variant: 'success', text: __('Existing transactions will be skipped.'));
    }

    /** Forget server_knowledge so the next fetch scans the whole account history. */
    public function resetCache(?int $accountId = null): void
    {
        Auth::user()->ynabAccounts()
            ->when($accountId, fn ($q) => $q->whereKey($accountId))
            ->update(['server_knowledge' => null]);

        Flux::toast(text: __('Cache reset — full history will be scanned.'));
    }

    public function refreshNames(): void
    {
        $user = Auth::user();

        try {
            $names = collect(app(YnabClient::class)->accounts($user->ynabPlanId()))->pluck('name', 'id');
        } catch (YnabException $e) {
            $this->error = $e->getMessage();

            return;
        }

        $user->ynabAccounts()->get()->each(function (YnabAccount $account) use ($names): void {
            if ($names->has($account->ynab_id)) {
                $account->update(['name' => self::cleanName($names[$account->ynab_id])]);
            }
        });

        $this->loadRemote();
        Flux::toast(variant: 'success', text: __('Account names refreshed.'));
    }

    public function clearRates(): void
    {
        Auth::user()->exchangeRates()->delete();

        Flux::toast(text: __('Rate cache cleared.'));
    }

    public static function cleanName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, YnabAccount> */
    #[Computed]
    public function localAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Auth::user()->ynabAccounts()->orderBy('name')->get();
    }

    #[Computed]
    public function rateCount(): int
    {
        return Auth::user()->exchangeRates()->count();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('YNAB settings') }}</flux:heading>

    <x-pages::settings.layout :heading="__('YNAB')" :subheading="__('Budget, accounts and caches used by the tools')">
        <div class="flex flex-col gap-10">
            @unless ($configured)
                <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('YNAB_TOKEN is not set')">
                    <flux:callout.text>{{ __('Add your personal access token to .env as YNAB_TOKEN (YNAB → Account settings → Developer settings).') }}</flux:callout.text>
                </flux:callout>
            @endunless

            @if ($error)
                <flux:callout variant="danger" icon="x-circle" :heading="$error">
                    <x-slot name="actions">
                        <flux:button wire:click="loadRemote" size="sm">{{ __('Retry') }}</flux:button>
                    </x-slot>
                </flux:callout>
            @endif

            {{-- Budget (replaces list-plans) --}}
            <form wire:submit="savePlan" class="flex flex-col gap-4">
                <flux:heading size="lg">{{ __('Budget') }}</flux:heading>
                <flux:select wire:model="planId" :label="__('YNAB budget')" data-test="plan-select">
                    <flux:select.option value="last-used">{{ __('Last used budget') }}</flux:select.option>
                    @foreach ($plans as $plan)
                        <flux:select.option :value="$plan['id']">{{ $plan['name'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div>
                    <flux:button type="submit" variant="primary" :disabled="! $configured">{{ __('Save budget') }}</flux:button>
                </div>
            </form>

            {{-- Accounts (replaces list-accounts + YNAB_ACCOUNTS) --}}
            <form wire:submit="saveAccounts" class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Foreign-currency accounts') }}</flux:heading>
                    <flux:text>{{ __('Enable the accounts whose transactions should be converted to PLN, and set their currency.') }}</flux:text>
                </div>

                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($remoteAccounts as $account)
                        <div class="flex items-center gap-3 px-3 py-2.5" wire:key="remote-{{ $account['id'] }}">
                            <flux:switch wire:model="selection.{{ $account['id'] }}.enabled" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ $account['name'] }}</div>
                                <div class="text-xs text-zinc-500">
                                    {{ number_format($account['balance'] / 1000, 2) }} · {{ $account['type'] }}
                                </div>
                            </div>
                            <flux:select wire:model="selection.{{ $account['id'] }}.currency" size="sm" class="max-w-24">
                                @foreach ($this::CURRENCIES as $currency)
                                    <flux:select.option :value="$currency">{{ $currency }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </div>
                    @empty
                        <div class="px-3 py-6 text-center text-sm text-zinc-500">{{ __('No YNAB accounts loaded.') }}</div>
                    @endforelse
                </div>

                <div>
                    <flux:button type="submit" variant="primary" :disabled="$remoteAccounts === []">{{ __('Save accounts') }}</flux:button>
                </div>
            </form>

            {{-- Maintenance (replaces init-cache) --}}
            <div class="flex flex-col gap-4">
                <div>
                    <flux:heading size="lg">{{ __('Caches') }}</flux:heading>
                    <flux:text>
                        {{ __('YNAB delta sync: after each conversion only newer transactions are fetched. "Skip existing" marks everything currently in an account as seen; "Reset" scans the full history again.') }}
                    </flux:text>
                </div>

                <div class="flex flex-col divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                    @forelse ($this->localAccounts as $account)
                        <div class="flex flex-wrap items-center gap-2 px-3 py-2.5" wire:key="local-{{ $account->id }}">
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">
                                    {{ $account->name }} <span class="text-zinc-500">[{{ $account->currency }}]</span>
                                    @unless ($account->enabled) <flux:badge size="sm">{{ __('disabled') }}</flux:badge> @endunless
                                </div>
                                <div class="text-xs text-zinc-500">server_knowledge: {{ $account->server_knowledge ?? '—' }}</div>
                            </div>
                            <flux:button size="sm" wire:click="initCache({{ $account->id }})">{{ __('Skip existing') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="resetCache({{ $account->id }})">{{ __('Reset') }}</flux:button>
                        </div>
                    @empty
                        <div class="px-3 py-6 text-center text-sm text-zinc-500">{{ __('No accounts saved yet.') }}</div>
                    @endforelse
                </div>

                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="initCache" :disabled="$this->localAccounts->isEmpty() || ! $configured">{{ __('Skip existing in all') }}</flux:button>
                    <flux:button size="sm" wire:click="refreshNames" :disabled="$this->localAccounts->isEmpty() || ! $configured">{{ __('Refresh account names') }}</flux:button>
                    <flux:button size="sm" variant="danger" wire:click="clearRates">{{ __('Clear rate cache') }} ({{ $this->rateCount }})</flux:button>
                </div>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
