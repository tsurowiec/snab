<?php

use App\Models\YnabAccount;
use App\Services\Conversions\ConversionService;
use App\Services\Conversions\PendingTransaction;
use App\Services\Rates\NbpRateService;
use App\Services\Ynab\YnabException;
use Livewire\Attributes\Lazy;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Convert transactions')] #[Lazy] class extends Component {
    public YnabAccount $account;

    /** @var list<array{id: string, date: string, amount: int, payeeName: string|null, memo: string|null, rate: float, rateDate: string, convertedAmount: int}> */
    public array $transactions = [];

    public ?string $error = null;

    /** Today's NBP rate, shown in the header (each row uses its own date's rate). */
    public ?float $todayRate = null;

    /** @var array{converted: int, ignored: int, dryRun: bool}|null */
    public ?array $result = null;

    public function mount(YnabAccount $account): void
    {
        $this->authorize('view', $account);

        $this->account = $account;
        $this->todayRate = app(NbpRateService::class)->rateFor($account->user, $account->currency, now()->toDateString())['rate'] ?? null;
        $this->load();
    }

    public function load(): void
    {
        $this->error = null;

        try {
            $this->transactions = array_map(
                fn (PendingTransaction $t): array => $t->toArray(),
                app(ConversionService::class)->pending($this->account),
            );
        } catch (YnabException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * @param  list<string>  $convertIds  ids to convert; every other listed transaction is ignored
     */
    public function convert(array $convertIds): void
    {
        $this->authorize('update', $this->account);

        $selections = [];
        foreach ($this->transactions as $t) {
            $selections[$t['id']] = in_array($t['id'], $convertIds, true);
        }

        try {
            $result = app(ConversionService::class)->apply($this->account, $selections);
            $this->result = ['converted' => $result->converted, 'ignored' => $result->ignored, 'dryRun' => $result->dryRun];
        } catch (YnabException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="flex flex-col items-center gap-3 py-14 text-zinc-500">
            <flux:icon.loading />
            <span>Loading transactions…</span>
        </div>
        HTML;
    }
}; ?>

<div class="mx-auto w-full max-w-xl">
    <flux:button :href="route('conversions.index')" wire:navigate icon="arrow-left" variant="primary" size="sm" class="mb-3">
        {{ __('Back') }}
    </flux:button>

    @if ($result)
        <div class="flex flex-col items-center gap-4 py-14 text-center" data-test="conversion-result">
            <div class="flex size-15 items-center justify-center rounded-full bg-green-100 text-2xl text-green-600 dark:bg-green-900/40">✓</div>
            <flux:heading size="lg">{{ $result['dryRun'] ? __('Dry run complete') : __('Done!') }}</flux:heading>
            <flux:text>{{ $result['converted'] }} {{ __('converted') }}, {{ $result['ignored'] }} {{ __('ignored') }}.</flux:text>
            <flux:button :href="route('conversions.index')" wire:navigate variant="primary">{{ __('Back to accounts') }}</flux:button>
        </div>
    @elseif ($error)
        <flux:callout variant="danger" icon="x-circle" :heading="$error">
            <x-slot name="actions">
                <flux:button wire:click="load" size="sm">{{ __('Retry') }}</flux:button>
            </x-slot>
        </flux:callout>
    @elseif ($transactions === [])
        <div class="py-14 text-center text-zinc-500">{{ __('No unprocessed transactions.') }}</div>
    @else
        <div x-data="conversionList(@js($transactions))" wire:key="list-{{ count($transactions) }}">
            <div class="mb-2.5 text-center">
                <flux:heading size="lg" level="1">{{ $account->name }}</flux:heading>
                @if ($todayRate)
                    <flux:text class="mt-1">1.00 {{ $account->currency }} → {{ number_format($todayRate, 4) }} PLN</flux:text>
                @endif
            </div>

            <div class="mb-2 flex items-center justify-between border-b border-zinc-200 py-2 dark:border-zinc-700">
                <label class="flex cursor-pointer items-center gap-2 text-sm text-zinc-500 select-none">
                    <input type="checkbox" class="size-4 accent-accent" :checked="allChecked" x-on:change="toggleAll()">
                    {{ __('Select all') }}
                </label>
                <span class="text-sm text-zinc-500">{{ count($transactions) }} {{ __('transactions') }}</span>
            </div>

            <div class="flex flex-col gap-1.5 pb-4">
                <template x-for="t in transactions" :key="t.id">
                    <label
                        class="flex cursor-pointer items-start gap-3 rounded-[10px] border border-zinc-200 bg-white p-3 transition-opacity dark:border-zinc-700 dark:bg-zinc-900"
                        :class="isChecked(t.id) || 'opacity-45'"
                        x-on:touchstart.passive="touchStart($event)"
                        x-on:touchmove.passive="touchMove($event, $el)"
                        x-on:touchend="touchEnd($event, $el, t.id)"
                        data-test="transaction-row"
                    >
                        <input type="checkbox" class="mt-1 size-4 shrink-0 accent-accent" :checked="isChecked(t.id)" x-on:change="onChange(t.id)">
                        <div class="min-w-0 flex-1">
                            <div class="mb-1 flex items-baseline justify-center gap-1.5 overflow-hidden">
                                <span class="shrink-0 text-xs text-zinc-500" x-text="t.date"></span>
                                <span class="min-w-0 shrink truncate text-sm font-medium" x-text="t.payeeName ?? '—'"></span>
                                <span x-show="t.memo" class="min-w-0 shrink-[2] truncate text-[13px] text-zinc-500" x-text="t.memo"></span>
                            </div>
                            <div class="mt-0.5 flex items-baseline justify-center gap-2 text-[17px]">
                                <span class="text-zinc-500" x-text="`${money(t.amount)} {{ $account->currency }}`"></span>
                                <span class="text-zinc-500">→</span>
                                <span class="font-semibold" x-text="`${money(t.convertedAmount)} PLN`"></span>
                            </div>
                            <div class="mt-0.5 text-center text-xs text-zinc-500" x-text="`@ ${t.rate.toFixed(4)} (${t.rateDate})`"></div>
                        </div>
                    </label>
                </template>
            </div>

            <div class="sticky bottom-0 -mx-4 border-t border-zinc-200 bg-white px-4 pt-3 pb-8 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-2.5 min-h-5 text-center text-[17px] text-zinc-500">
                    <template x-if="totals.convertCount > 0">
                        <span>
                            <span x-text="`${totals.totalOrig.toFixed(2)} {{ $account->currency }} →`"></span>
                            <strong class="text-zinc-900 dark:text-white" x-text="`${totals.totalPln.toFixed(2)} PLN`"></strong>
                        </span>
                    </template>
                </div>
                <div class="flex justify-center">
                    <flux:button
                        variant="primary"
                        x-on:click="$flux.modal('confirm-conversion').show()"
                        x-bind:disabled="transactions.length === 0"
                        wire:loading.attr="disabled"
                        wire:target="convert"
                        data-test="convert-button"
                    >
                        <span x-text="buttonLabel"></span>
                    </flux:button>
                </div>
            </div>

            <flux:modal name="confirm-conversion" class="w-full max-w-md">
                <div class="flex flex-col gap-4">
                    <flux:heading size="lg" class="text-center">{{ __('Confirm') }}</flux:heading>
                    <ul class="flex flex-col gap-3.5 text-center text-sm">
                        <template x-if="totals.convertCount > 0">
                            <li>
                                <strong x-text="`Convert ${totals.convertCount} transaction${totals.convertCount !== 1 ? 's' : ''}`"></strong>
                                <div class="mt-1 flex items-baseline justify-center gap-2 text-[17px]">
                                    <span class="text-zinc-500" x-text="`${totals.totalOrig.toFixed(2)} {{ $account->currency }}`"></span>
                                    <span class="text-zinc-500">→</span>
                                    <span class="font-semibold" x-text="`${totals.totalPln.toFixed(2)} PLN`"></span>
                                </div>
                            </li>
                        </template>
                        <template x-if="totals.ignoreCount > 0">
                            <li><strong x-text="`Ignore ${totals.ignoreCount} transaction${totals.ignoreCount !== 1 ? 's' : ''}`"></strong></li>
                        </template>
                    </ul>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" x-on:click="confirm()" data-test="confirm-conversion">
                            {{ __('Confirm') }}
                        </flux:button>
                    </div>
                </div>
            </flux:modal>
        </div>
    @endif
</div>
