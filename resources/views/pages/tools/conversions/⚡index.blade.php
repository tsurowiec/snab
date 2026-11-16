<?php

use App\Services\Conversions\ConversionService;
use App\Services\Rates\NbpRateService;
use App\Services\Ynab\YnabException;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Conversions')] #[Lazy] class extends Component {
    /** @var list<array{id: int, name: string, currency: string, pending: int, rate: float|null, rateDate: string|null}> */
    public array $accounts = [];

    public ?string $error = null;

    public ?string $refreshedAt = null;

    public bool $configured = true;

    public function mount(): void
    {
        $this->load();
    }

    public function load(): void
    {
        $conversions = app(ConversionService::class);
        $rates = app(NbpRateService::class);
        $this->error = null;
        $user = Auth::user();
        $this->configured = $user->hasYnabToken();
        $today = now()->toDateString();
        $rateByCurrency = [];
        $rows = [];

        try {
            foreach ($user->ynabAccounts()->where('enabled', true)->orderBy('name')->get() as $account) {
                $rate = $rateByCurrency[$account->currency] ??= $rates->rateFor($user, $account->currency, $today);

                $rows[] = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'currency' => $account->currency,
                    'pending' => $this->configured ? $conversions->pendingCount($account) : 0,
                    'rate' => $rate['rate'] ?? null,
                    'rateDate' => $rate['rateDate'] ?? null,
                ];
            }
        } catch (YnabException $e) {
            $this->error = $e->getMessage();
        }

        usort($rows, fn (array $a, array $b): int => $b['pending'] <=> $a['pending']);

        $this->accounts = $rows;
        $this->refreshedAt = now()->toIso8601String();
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col items-center gap-3 py-14 text-zinc-500">
            <flux:icon.loading />
            <span>Loading accounts…</span>
        </div>
        HTML;
    }
}; ?>

<div
    class="mx-auto w-full max-w-xl"
    x-on:visibilitychange.document="document.visibilityState === 'visible' && $wire.load()"
>
    <div class="mb-4 flex items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Conversions') }}</flux:heading>
            <flux:text
                x-data="{ now: Date.now() }"
                x-init="setInterval(() => now = Date.now(), 30000)"
                x-text="(() => {
                    const min = Math.floor((now - Date.parse($wire.refreshedAt)) / 60000);
                    if (isNaN(min) || min < 1) return 'Updated just now';
                    return min < 60 ? `Updated ${min} min ago` : `Updated ${Math.floor(min / 60)}h ago`;
                })()"
            ></flux:text>
        </div>
        <flux:button wire:click="load" icon="arrow-path" variant="primary" data-test="refresh-accounts">
            {{ __('Refresh') }}
        </flux:button>
    </div>

    @unless ($configured)
        <flux:callout variant="warning" icon="exclamation-triangle" class="mb-4" :heading="__('No YNAB token set')">
            <flux:callout.text>
                <flux:link :href="route('ynab.edit')" wire:navigate>{{ __('Add your personal access token in Settings → YNAB.') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endunless

    @if ($error)
        <flux:callout variant="danger" icon="x-circle" class="mb-4" :heading="$error">
            <x-slot name="actions">
                <flux:button wire:click="load" size="sm">{{ __('Retry') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <div wire:loading.class="opacity-50" wire:target="load" class="flex flex-col gap-2">
        @forelse ($accounts as $account)
            @php($disabled = $account['pending'] === 0)
            <a
                @unless ($disabled) href="{{ route('conversions.show', $account['id']) }}" wire:navigate @endunless
                @class([
                    'flex flex-col rounded-xl border border-zinc-200 bg-white px-4 py-3.5 transition dark:border-zinc-700 dark:bg-zinc-900',
                    'hover:border-accent hover:ring-3 hover:ring-accent/10' => ! $disabled,
                    'pointer-events-none opacity-45' => $disabled,
                ])
                data-test="account-card"
            >
                <div class="flex items-center justify-between gap-3">
                    <span class="text-[17px] font-medium">
                        {{ $account['name'] }}
                        <span class="text-sm font-normal text-zinc-500">[{{ $account['currency'] }}]</span>
                    </span>
                    <flux:badge size="sm" :color="$disabled ? 'zinc' : 'blue'" rounded>
                        {{ $account['pending'] }} {{ __('pending') }}
                    </flux:badge>
                </div>
                @if ($account['rate'])
                    <div class="mt-1.5 text-sm text-zinc-500">
                        {{ number_format($account['rate'], 4) }} PLN · {{ \Carbon\CarbonImmutable::parse($account['rateDate'])->format('M j') }}
                    </div>
                @endif
            </a>
        @empty
            @unless ($error)
                <div class="py-14 text-center text-zinc-500">
                    <p>{{ __('No accounts configured yet.') }}</p>
                    <flux:link :href="route('ynab.edit')" wire:navigate>{{ __('Pick accounts in Settings → YNAB') }}</flux:link>
                </div>
            @endunless
        @endforelse
    </div>
</div>
