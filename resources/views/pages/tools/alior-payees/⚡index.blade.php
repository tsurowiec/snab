<?php

use App\Models\LlmUsage;
use App\Services\AliorPayees\AliorPayeeService;
use App\Services\AliorPayees\AliorRow;
use App\Services\AliorPayees\PayeeExtractor;
use App\Services\Anthropic\ScreenshotReader;
use App\Services\CardImport\ExtractionException;
use App\Services\Ynab\YnabException;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Alior payees: Alior's bank sync brings transactions into YNAB with wrong payees. Upload Alior screenshots →
 * Claude reads date, merchant and amount → match to YNAB by amount + date → rename payees / add missing ones.
 * Stateless: nothing is stored, the review is always against live YNAB.
 */
new #[Title('Alior payees')] class extends Component {
    use WithFileUploads;

    /** @var list<TemporaryUploadedFile> */
    #[Validate(['screenshots' => ['required', 'array', 'max:10'], 'screenshots.*' => ['image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120']])]
    public array $screenshots = [];

    /** @var list<array{date: string, merchant: string, amount: int, action: string, ynabId: string|null, ynabPayee: string|null, ynabDate: string|null, selected: bool}> */
    public array $rows = [];

    /** @var list<string> */
    public array $extractErrors = [];

    public ?string $ynabError = null;

    /** @var array{calls: int, inputTokens: int, outputTokens: int, costMicros: int|null}|null */
    public ?array $lastUsage = null;

    public function extract(): void
    {
        $this->validate();

        $user = Auth::user();
        $extractor = app(PayeeExtractor::class);
        $this->extractErrors = [];
        $this->ynabError = null;
        $usageBefore = (int) $user->llmUsages()->max('id');

        // Overlapping screenshots repeat rows; two equal purchases on one day are real. So keep each distinct
        // row as many times as the screenshot with the most copies of it shows it.
        /** @var array<string, array{row: AliorRow, count: int}> $found */
        $found = [];

        foreach ($this->screenshots as $file) {
            try {
                $mime = (string) $file->getMimeType();
                if (! in_array($mime, ScreenshotReader::MIME_TYPES, true)) {
                    throw new ExtractionException(__('Unsupported image type :type.', ['type' => $mime]));
                }
                $counts = [];
                foreach ($extractor->extract($user, (string) $file->get(), $mime) as $row) {
                    $key = $row->importId();
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $found[$key] = ['row' => $row, 'count' => max($found[$key]['count'] ?? 0, $counts[$key])];
                }
            } catch (ExtractionException $e) {
                $this->extractErrors[] = $file->getClientOriginalName().': '.$e->getMessage();
            }
        }

        $rows = [];
        foreach ($found as $entry) {
            array_push($rows, ...array_fill(0, $entry['count'], $entry['row']));
        }
        usort($rows, fn (AliorRow $a, AliorRow $b): int => $b->date <=> $a->date);

        $this->screenshots = [];
        $this->lastUsage = $this->usageSince($usageBefore);

        if ($rows === []) {
            if ($this->extractErrors === []) {
                $this->extractErrors[] = __('No transactions found in the screenshot.');
            }

            return;
        }

        try {
            $preview = app(AliorPayeeService::class)->preview($user, $rows);
        } catch (YnabException $e) {
            $this->ynabError = $e->getMessage();

            return;
        }

        $this->rows = array_map(fn (array $p): array => [
            ...$p['row']->toArray(),
            'action' => $p['action'],
            'ynabId' => $p['ynabId'],
            'ynabPayee' => $p['ynabPayee'],
            'ynabDate' => $p['ynabDate'],
            'selected' => $p['action'] !== AliorPayeeService::ACTION_OK,
        ], $preview);
    }

    public function apply(): void
    {
        $renames = [];
        $creates = [];

        foreach ($this->rows as $row) {
            if (! $row['selected']) {
                continue;
            }
            if ($row['action'] === AliorPayeeService::ACTION_RENAME && $row['ynabId'] !== null) {
                $renames[] = ['id' => $row['ynabId'], 'payee' => $row['merchant']];
            } elseif ($row['action'] === AliorPayeeService::ACTION_CREATE) {
                $creates[] = AliorRow::fromArray($row);
            }
        }

        $this->ynabError = null;

        try {
            $result = app(AliorPayeeService::class)->apply(Auth::user(), $renames, $creates);
        } catch (YnabException $e) {
            $this->ynabError = $e->getMessage();

            return;
        }

        $this->rows = [];

        $message = __(':renamed renamed, :created added to YNAB.', $result);
        if ($result['alreadyInYnab'] > 0) {
            $message .= ' '.__(':count were already in YNAB.', ['count' => $result['alreadyInYnab']]);
        }

        Flux::toast(variant: 'success', text: $message);
    }

    public function discard(): void
    {
        $this->rows = [];
        $this->extractErrors = [];
        $this->ynabError = null;
    }

    /** @return list<string> what's missing before the tool can run */
    #[Computed]
    public function missing(): array
    {
        $user = Auth::user();

        return array_keys(array_filter([
            'anthropic' => ! $user->hasAnthropicApiKey(),
            'ynab' => ! $user->hasYnabToken() || app(AliorPayeeService::class)->accountId($user) === null,
        ]));
    }

    #[Computed]
    public function selectedCount(): int
    {
        return count(array_filter($this->rows, fn (array $row): bool => $row['selected'] && $row['action'] !== AliorPayeeService::ACTION_OK));
    }

    /** @return array{calls: int, inputTokens: int, outputTokens: int, costMicros: int|null}|null */
    private function usageSince(int $id): ?array
    {
        $usages = Auth::user()->llmUsages()->where('id', '>', $id)->get();
        if ($usages->isEmpty()) {
            return null;
        }

        return [
            'calls' => $usages->count(),
            'inputTokens' => (int) $usages->sum(fn (LlmUsage $u): int => $u->input_tokens + $u->cache_creation_input_tokens + $u->cache_read_input_tokens),
            'outputTokens' => (int) $usages->sum('output_tokens'),
            'costMicros' => $usages->contains(fn (LlmUsage $u): bool => $u->cost_micros === null) ? null : (int) $usages->sum('cost_micros'),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-2xl flex-col gap-6">
    @php($money = fn (int $milliunits): string => number_format($milliunits / 1000, 2, '.', ' '))
    @php($ready = $this->missing === [])

    <div>
        <flux:heading size="xl" level="1">{{ __('Alior payees') }}</flux:heading>
        <flux:text>{{ __('Upload screenshots of your Alior transactions. Payees of transactions already in YNAB are fixed, missing ones are added.') }}</flux:text>
    </div>

    @if (in_array('anthropic', $this->missing, true))
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No Anthropic API key set')">
            <flux:callout.text>
                <flux:link :href="route('ai.edit')" wire:navigate>{{ __('Add your API key in Settings → AI.') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif
    @if (in_array('ynab', $this->missing, true))
        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('No Alior account chosen')">
            <flux:callout.text>
                <flux:link :href="route('ynab.edit')" wire:navigate>{{ __('Set a YNAB token and the Alior account in Settings → YNAB.') }}</flux:link>
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- 1. Upload --}}
    @if ($rows === [])
        <form wire:submit="extract" x-data="cardImportPaste" x-on:paste.window="handlePaste($event)" class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-col gap-2">
                <flux:label>{{ __('Paste a screenshot') }}</flux:label>
                <textarea
                    rows="2"
                    readonly
                    x-on:input="$el.value = ''"
                    x-on:focus="$el.removeAttribute('readonly')"
                    x-on:blur="$el.setAttribute('readonly', '')"
                    :disabled="busy || {{ $ready ? 'false' : 'true' }}"
                    placeholder="{{ __('Press Ctrl/⌘+V, or tap here and choose Paste') }}"
                    class="w-full resize-none rounded-lg border border-dashed border-zinc-300 bg-zinc-50 px-3 py-4 text-center text-sm text-zinc-500 placeholder:text-zinc-500 focus:border-accent focus:outline-none dark:border-zinc-600 dark:bg-zinc-800"
                    data-test="paste-box"
                ></textarea>
                <template x-if="canReadClipboard">
                    <div>
                        <flux:button size="sm" icon="clipboard" x-on:click="readClipboard()" x-bind:disabled="busy" :disabled="! $ready">
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
                <flux:button type="submit" variant="primary" icon="sparkles" :disabled="! $ready" wire:loading.attr="disabled" wire:target="extract,screenshots" data-test="extract-button">
                    {{ __('Read & match with YNAB') }}
                </flux:button>
                <span wire:loading wire:target="extract" class="text-sm text-zinc-500">{{ __('Claude is reading the screenshot…') }}</span>
            </div>
        </form>
    @endif

    @if ($lastUsage)
        <flux:text class="text-sm" data-test="extraction-cost">
            {{ trans_choice('{1} Claude read 1 screenshot|[2,*] Claude read :count screenshots', $lastUsage['calls'], ['count' => $lastUsage['calls']]) }}
            · {{ number_format($lastUsage['inputTokens'], 0, '.', ' ') }} {{ __('in') }} / {{ number_format($lastUsage['outputTokens'], 0, '.', ' ') }} {{ __('out tokens') }}
            · <strong>{{ LlmUsage::formatCost($lastUsage['costMicros']) }}</strong>
        </flux:text>
    @endif

    @foreach ($extractErrors as $message)
        <flux:callout variant="danger" icon="x-circle" :heading="$message" />
    @endforeach

    @if ($ynabError)
        <flux:callout variant="danger" icon="x-circle" :heading="$ynabError" data-test="ynab-error" />
    @endif

    {{-- 2. Review --}}
    @if ($rows !== [])
        <div class="flex flex-col gap-2" data-test="review-list">
            <flux:heading size="lg">{{ __('Review') }}</flux:heading>

            @foreach ($rows as $i => $row)
                @php($ok = $row['action'] === AliorPayeeService::ACTION_OK)
                <label wire:key="row-{{ $i }}-{{ $row['date'] }}-{{ $row['amount'] }}" @class([
                    'flex items-center gap-3 rounded-[10px] border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900',
                    'opacity-45' => $ok || ! $row['selected'],
                    'cursor-pointer' => ! $ok,
                ]) data-test="review-row">
                    <input type="checkbox" class="size-4 shrink-0 accent-accent" wire:model.live="rows.{{ $i }}.selected" @disabled($ok)>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-baseline gap-2">
                            <span class="shrink-0 text-xs text-zinc-500">{{ $row['date'] }}</span>
                            <span class="truncate font-medium">{{ $row['merchant'] }}</span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span @class(['font-semibold', 'text-green-600' => $row['amount'] > 0])>{{ $money($row['amount']) }} PLN</span>
                            @if ($row['action'] === AliorPayeeService::ACTION_RENAME)
                                <flux:badge size="sm" color="blue">{{ __('rename: :payee', ['payee' => $row['ynabPayee'] ?? '—']) }}</flux:badge>
                            @elseif ($row['action'] === AliorPayeeService::ACTION_CREATE)
                                <flux:badge size="sm" color="amber">{{ __('not in YNAB — add') }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="green">{{ __('OK') }}</flux:badge>
                            @endif
                            @if ($row['ynabDate'] !== null && $row['ynabDate'] !== $row['date'])
                                <flux:badge size="sm" color="zinc">{{ __('YNAB date :date', ['date' => $row['ynabDate']]) }}</flux:badge>
                            @endif
                        </div>
                    </div>
                </label>
            @endforeach

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="discard">{{ __('Discard') }}</flux:button>
                <flux:button variant="primary" wire:click="apply" :disabled="$this->selectedCount === 0" data-test="apply-button">
                    {{ trans_choice('{0} Nothing to change|{1} Apply 1 change in YNAB|[2,*] Apply :count changes in YNAB', $this->selectedCount, ['count' => $this->selectedCount]) }}
                </flux:button>
            </div>
        </div>
    @endif
</div>
