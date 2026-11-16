<?php

use App\Models\CardTransaction;
use App\Services\CardImport\CardImportService;
use App\Services\CardImport\ClaudeTransactionExtractor;
use App\Services\CardImport\ExtractedTransaction;
use App\Services\CardImport\ExtractionException;
use App\Services\CardImport\TransactionExtractor;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Card Import: upload Revolut screenshots → Claude extracts the transactions → review → store (deduped by hash).
 * Screenshots are not kept; only the extracted rows are stored.
 */
new #[Title('Card import')] class extends Component {
    use WithFileUploads, WithPagination;

    /** @var list<TemporaryUploadedFile> */
    #[Validate(['screenshots' => ['required', 'array', 'max:10'], 'screenshots.*' => ['image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120']])]
    public array $screenshots = [];

    /** @var list<array{occurredAt: string, description: string, amount: int, currency: string, originalAmount: int|null, originalCurrency: string|null, status: string, hash: string, duplicate: bool, selected: bool}> */
    public array $rows = [];

    /** @var list<string> */
    public array $extractErrors = [];

    public bool $configured = false;

    public function mount(): void
    {
        $this->configured = Auth::user()->hasAnthropicApiKey();
    }

    public function extract(): void
    {
        $this->validate();

        $user = Auth::user();
        $extractor = app(TransactionExtractor::class);
        $this->extractErrors = [];
        $found = [];

        foreach ($this->screenshots as $file) {
            try {
                $mime = (string) $file->getMimeType();
                if (! in_array($mime, ClaudeTransactionExtractor::MIME_TYPES, true)) {
                    throw new ExtractionException(__('Unsupported image type :type.', ['type' => $mime]));
                }
                array_push($found, ...$extractor->extract($user, (string) $file->get(), $mime));
            } catch (ExtractionException $e) {
                $this->extractErrors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        // Overlapping screenshots (or the same image twice) yield identical rows — keep one of each.
        $unique = [];
        foreach ($found as $row) {
            $unique[$row->hash()] ??= $row;
        }
        $found = array_values($unique);

        usort($found, fn (ExtractedTransaction $a, ExtractedTransaction $b): int => $b->occurredAt <=> $a->occurredAt);

        $this->rows = array_map(
            fn (array $p): array => [
                ...$p['transaction']->toArray(),
                'duplicate' => $p['duplicate'],
                'selected' => ! $p['duplicate'] && $p['transaction']->selectedByDefault(),
            ],
            app(CardImportService::class)->preview($user, $found),
        );

        $this->screenshots = [];

        if ($found === [] && $this->extractErrors === []) {
            $this->extractErrors[] = __('No transactions found in the screenshot.');
        }
    }

    public function save(): void
    {
        $selected = array_values(array_map(
            fn (array $row): ExtractedTransaction => ExtractedTransaction::fromArray($row),
            array_filter($this->rows, fn (array $row): bool => $row['selected'] && ! $row['duplicate']),
        ));

        $result = app(CardImportService::class)->store(Auth::user(), $selected);
        $duplicates = count(array_filter($this->rows, fn (array $row): bool => $row['duplicate']));

        $this->rows = [];
        $this->resetPage();

        Flux::toast(variant: 'success', text: __(':saved saved, :skipped already imported.', [
            'saved' => $result['saved'],
            'skipped' => $result['skipped'] + $duplicates,
        ]));
    }

    public function discard(): void
    {
        $this->rows = [];
        $this->extractErrors = [];
    }

    /** @return LengthAwarePaginator<int, CardTransaction> */
    #[Computed]
    public function saved(): LengthAwarePaginator
    {
        return Auth::user()->cardTransactions()->latest('occurred_at')->latest('id')->paginate(25);
    }

    #[Computed]
    public function selectedCount(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['selected'] && ! $row['duplicate']));
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    @php($money = fn (int $milliunits): string => number_format($milliunits / 1000, 2, '.', ' '))

    <div>
        <flux:heading size="xl" level="1">{{ __('Card import') }}</flux:heading>
        <flux:text>{{ __('Upload screenshots of your Revolut card transactions. Each transaction is stored only once.') }}</flux:text>
    </div>

    @unless ($configured)
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No Anthropic API key set')">
            <flux:callout.text>
                <flux:link :href="route('ai.edit')" wire:navigate>{{ __('Add your API key in Settings → AI.') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endunless

    {{-- 1. Upload --}}
    @if ($rows === [])
        <form wire:submit="extract" x-data="cardImportPaste" x-on:paste.window="handlePaste($event)" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            {{-- Paste: Ctrl/⌘+V anywhere, the box below (phones), or the clipboard button --}}
            <div class="flex flex-col gap-2">
                <flux:label>{{ __('Paste a screenshot') }}</flux:label>
                <textarea
                    rows="2"
                    readonly
                    x-on:input="$el.value = ''"
                    x-on:focus="$el.removeAttribute('readonly')"
                    x-on:blur="$el.setAttribute('readonly', '')"
                    :disabled="busy || {{ $configured ? 'false' : 'true' }}"
                    placeholder="{{ __('Press Ctrl/⌘+V, or tap here and choose Paste') }}"
                    class="w-full resize-none rounded-lg border border-dashed border-zinc-300 bg-zinc-50 px-3 py-4 text-center text-sm text-zinc-500 placeholder:text-zinc-500 focus:border-accent focus:outline-none dark:border-zinc-600 dark:bg-zinc-800"
                    data-test="paste-box"
                ></textarea>
                <template x-if="canReadClipboard">
                    <div>
                        <flux:button size="sm" icon="clipboard" x-on:click="readClipboard()" x-bind:disabled="busy" :disabled="! $configured">
                            {{ __('Paste from clipboard') }}
                        </flux:button>
                    </div>
                </template>
                <flux:text x-show="error" x-text="error" class="text-red-600!"></flux:text>
            </div>

            <flux:separator :text="__('or')" />

            <flux:input type="file" wire:model="screenshots" multiple accept="image/png,image/jpeg,image/webp,image/gif" :label="__('Choose files')" data-test="screenshot-input" />
            <div wire:loading wire:target="screenshots" class="text-sm text-zinc-500">{{ __('Uploading…') }}</div>

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary" icon="sparkles" :disabled="! $configured" wire:loading.attr="disabled" wire:target="extract,screenshots" data-test="extract-button">
                    {{ __('Extract transactions') }}
                </flux:button>
                <span wire:loading wire:target="extract" class="text-sm text-zinc-500">{{ __('Claude is reading the screenshot…') }}</span>
            </div>
        </form>
    @endif

    @foreach ($extractErrors as $message)
        <flux:callout variant="danger" icon="x-circle" :heading="$message" />
    @endforeach

    {{-- 2. Review --}}
    @if ($rows !== [])
        <div class="flex flex-col gap-2" data-test="review-list">
            <flux:heading size="lg">{{ __('Review') }}</flux:heading>

            @foreach ($rows as $i => $row)
                <label wire:key="row-{{ $row['hash'] }}-{{ $i }}" @class([
                    'flex items-center gap-3 rounded-[10px] border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900',
                    'opacity-45' => $row['duplicate'] || ! $row['selected'],
                    'cursor-pointer' => ! $row['duplicate'],
                ]) data-test="review-row">
                    <input type="checkbox" class="size-4 shrink-0 accent-accent" wire:model.live="rows.{{ $i }}.selected" @disabled($row['duplicate'])>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="shrink-0 text-xs text-zinc-500">{{ $row['occurredAt'] }}</span>
                            <span class="truncate font-medium">{{ $row['description'] }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span @class(['font-semibold', 'text-green-600' => $row['amount'] > 0])>{{ $money($row['amount']) }} {{ $row['currency'] }}</span>
                            @if ($row['originalAmount'] !== null)
                                <span class="text-zinc-500">({{ $money($row['originalAmount']) }} {{ $row['originalCurrency'] }})</span>
                            @endif
                            @if ($row['status'] !== 'completed')
                                <flux:badge size="sm" color="amber">{{ $row['status'] }}</flux:badge>
                            @endif
                            @if ($row['duplicate'])
                                <flux:badge size="sm" color="zinc">{{ __('already imported') }}</flux:badge>
                            @endif
                        </div>
                    </div>
                </label>
            @endforeach

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="discard">{{ __('Discard') }}</flux:button>
                <flux:button variant="primary" wire:click="save" :disabled="$this->selectedCount === 0" data-test="save-button">
                    {{ trans_choice('{0} Nothing to save|{1} Save 1 transaction|[2,*] Save :count transactions', $this->selectedCount, ['count' => $this->selectedCount]) }}
                </flux:button>
            </div>
        </div>
    @endif

    {{-- 3. Stored --}}
    <div class="flex flex-col gap-2">
        <flux:heading size="lg">{{ __('Imported transactions') }}</flux:heading>

        @forelse ($this->saved as $tx)
            <div wire:key="saved-{{ $tx->id }}" class="flex items-center justify-between gap-3 border-b border-zinc-200 py-2 dark:border-zinc-700" data-test="saved-row">
                <div class="min-w-0">
                    <div class="truncate font-medium">{{ $tx->description }}</div>
                    <div class="text-xs text-zinc-500">{{ $tx->occurred_at->format('Y-m-d H:i') }}</div>
                </div>
                <div class="flex shrink-0 items-center gap-2 text-sm">
                    <span @class(['font-semibold', 'text-green-600' => $tx->amount > 0])>{{ $money($tx->amount) }} {{ $tx->currency }}</span>
                    <flux:badge size="sm" :color="$tx->status === CardTransaction::STATUS_RECORDED ? 'green' : 'blue'">{{ $tx->status }}</flux:badge>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Nothing imported yet.') }}</flux:text>
        @endforelse

        {{ $this->saved->links() }}
    </div>
</div>
